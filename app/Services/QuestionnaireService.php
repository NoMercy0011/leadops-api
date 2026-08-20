<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectAnswer;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Support\Questionnaire\ValidationRuleBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Construction du questionnaire et enregistrement des réponses.
 *
 * La validation n'est jamais écrite ici : elle est déléguée à
 * ValidationRuleBuilder, seul endroit qui connaît la correspondance entre un
 * type de question et ses règles.
 */
final class QuestionnaireService
{
    public function __construct(private readonly ValidationRuleBuilder $regles) {}

    /**
     * Questions actives d'un projet, avec leurs options.
     *
     * @return Collection<int, Question>
     */
    public function questionsFor(Project $project): Collection
    {
        return Question::query()
            ->where('project_id', $project->id)
            ->with('options')
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function createQuestion(Project $project, array $donnees): Question
    {
        return DB::transaction(function () use ($project, $donnees) {
            $question = Question::query()->create([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'type' => $donnees['type'],
                'label' => $donnees['label'],
                'help' => $donnees['help'] ?? null,
                'required' => $donnees['required'] ?? false,
                'position' => $this->nextPosition($project),
            ]);

            $this->syncOptions($question, $donnees['options'] ?? []);

            return $question->load('options');
        });
    }

    /**
     * Modifie une question.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException
     */
    public function updateQuestion(Question $question, array $donnees): Question
    {
        // Changer le type d'une question déjà répondue rendrait les réponses
        // existantes ininterprétables : un « oui » devenu un nombre n'a plus de
        // sens, et la validation à venir les rejetterait toutes. La marche à
        // suivre est de créer une nouvelle question.
        if (
            isset($donnees['type'])
            && $donnees['type'] !== $question->type->value
            && $question->hasAnswers()
        ) {
            throw ValidationException::withMessages([
                'type' => 'Cette question a déjà des réponses : son type ne peut plus changer. Créez une nouvelle question à la place.',
            ]);
        }

        return DB::transaction(function () use ($question, $donnees) {
            $question->update(array_intersect_key($donnees, array_flip([
                'type', 'label', 'help', 'required',
            ])));

            if (array_key_exists('options', $donnees)) {
                $this->syncOptions($question, $donnees['options']);
            }

            return $question->fresh(['options']);
        });
    }

    /**
     * Réordonne les questions d'un projet.
     *
     * @param  list<int>  $orderedIds
     *
     * @throws ValidationException
     */
    public function reorder(Project $project, array $orderedIds): void
    {
        $existants = Question::query()
            ->where('project_id', $project->id)
            ->pluck('id')
            ->all();

        sort($existants);
        $fournis = $orderedIds;
        sort($fournis);

        if ($existants !== $fournis) {
            throw ValidationException::withMessages([
                'questions' => 'La liste doit contenir exactement les questions du projet, chacune une seule fois.',
            ]);
        }

        DB::transaction(function () use ($project, $orderedIds) {
            // Décalage en deux temps, comme pour les étapes de pipeline : la
            // contrainte d'unicité sur (project_id, position) rejetterait une
            // réécriture directe.
            $offset = count($orderedIds) + 1000;

            foreach ($orderedIds as $index => $id) {
                Question::query()
                    ->where('project_id', $project->id)
                    ->whereKey($id)
                    ->update(['position' => $offset + $index]);
            }

            foreach ($orderedIds as $index => $id) {
                Question::query()
                    ->where('project_id', $project->id)
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * Valide et enregistre les réponses d'un prospect.
     *
     * @param  array<int|string, mixed>  $reponses  indexées par identifiant de question
     * @return Collection<int, ProspectAnswer>
     *
     * @throws ValidationException
     */
    public function saveAnswers(Prospect $prospect, array $reponses): Collection
    {
        $questions = $this->questionsFor($prospect->project);

        // Les règles sont construites à l'exécution depuis les définitions :
        // c'est tout l'objet de l'invariant n°3.
        $validees = Validator::make(
            ['answers' => $reponses],
            $this->regles->forQuestions($questions),
            $this->regles->messages($questions),
        )->validate();

        $valeurs = $validees['answers'] ?? [];

        return DB::transaction(function () use ($prospect, $questions, $valeurs) {
            foreach ($questions as $question) {
                // `array_key_exists` et non `isset` : une réponse volontairement
                // effacée arrive à `null`, qu'`isset` confondrait avec une
                // question laissée de côté.
                if (! array_key_exists($question->id, $valeurs)) {
                    continue;
                }

                $valeur = $this->regles->cast($question, $valeurs[$question->id]);

                if ($valeur === null) {
                    ProspectAnswer::query()
                        ->where('prospect_id', $prospect->id)
                        ->where('question_id', $question->id)
                        ->delete();

                    continue;
                }

                ProspectAnswer::query()->updateOrCreate(
                    ['prospect_id' => $prospect->id, 'question_id' => $question->id],
                    ['company_id' => $prospect->company_id, 'value' => $valeur],
                );
            }

            return $this->answersFor($prospect);
        });
    }

    /**
     * @return Collection<int, ProspectAnswer>
     */
    public function answersFor(Prospect $prospect): Collection
    {
        return ProspectAnswer::query()
            ->where('prospect_id', $prospect->id)
            ->with('question.options')
            ->get();
    }

    /**
     * Remplace la liste d'options d'une question.
     *
     * Les options encore présentes sont mises à jour plutôt que recréées : les
     * supprimer puis les réinsérer changerait leurs identifiants sans raison,
     * et une option retirée puis remise perdrait son rattachement.
     *
     * @param  list<array{label: string, value?: string}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        if (! $question->type->requiresOptions()) {
            $question->options()->delete();

            return;
        }

        $valeursGardees = [];

        foreach ($options as $index => $option) {
            // La valeur par défaut dérive du libellé, mais reste indépendante :
            // renommer une option ne doit pas invalider les réponses déjà
            // enregistrées, qui référencent la valeur.
            $valeur = $option['value'] ?? str($option['label'])->slug()->value();

            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'value' => $valeur],
                [
                    'company_id' => $question->company_id,
                    'label' => $option['label'],
                    'position' => $index + 1,
                ],
            );

            $valeursGardees[] = $valeur;
        }

        $question->options()->whereNotIn('value', $valeursGardees)->delete();
    }

    private function nextPosition(Project $project): int
    {
        return (int) Question::query()
            ->withTrashed()
            ->where('project_id', $project->id)
            ->max('position') + 1;
    }
}
