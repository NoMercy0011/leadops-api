<?php

namespace App\Services;

use App\Exceptions\InterviewRefused;
use App\Models\Interview;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectAnswer;
use App\Models\Question;
use App\Models\QuestionTiming;
use App\Models\User;
use App\Support\Questionnaire\ValidationRuleBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Entretien guidé — lot 13, note `cadrage/LOT-13_Entretien-guide.md`.
 *
 * Deux écarts volontaires avec la saisie de la fiche (`saveAnswers`) :
 * - une réponse s'enregistre seule, **sans** la règle obligatoire : un
 *   entretien interrompu ne perd rien (R4, faille n°1 de l'analyse) ;
 * - l'obligation se contrôle à la fin, sur les réponses enregistrées.
 *
 * La simulation ne passe pas par ici : elle ne lit que le plan et n'écrit,
 * au besoin, que des durées personnelles (R7).
 */
final class InterviewService
{
    public const DUREE_MIN = 15;

    public const DUREE_MAX = 900;

    public function __construct(
        private readonly QuestionnaireService $questionnaire,
        private readonly ValidationRuleBuilder $regles,
        private readonly ActivityLogger $journal,
    ) {}

    /**
     * Plan d'entretien de l'utilisateur : questions, durées prévues et
     * personnelles, totaux.
     *
     * @return array{questions: Collection<int, Question>, mine: array<int, int>, totals: array{planned_seconds: int, my_seconds: int}}
     */
    public function plan(Project $project, User $user): array
    {
        $questions = $this->questionnaire->questionsFor($project);
        $miennes = QuestionTiming::query()
            ->where('user_id', $user->id)
            ->whereIn('question_id', $questions->pluck('id'))
            ->pluck('seconds', 'question_id')
            ->map(fn ($s) => (int) $s)
            ->all();

        return [
            'questions' => $questions,
            'mine' => $miennes,
            'totals' => [
                'planned_seconds' => (int) $questions->sum(fn (Question $q) => $q->planned_seconds ?? 0),
                'my_seconds' => (int) $questions->sum(fn (Question $q) => $miennes[$q->id] ?? $q->planned_seconds ?? 0),
            ],
        ];
    }

    /**
     * Garde des durées personnelles, arrondies à 5 secondes (décision n°4 de
     * la planche) et bornées.
     *
     * @param  list<array{question_id: int, seconds: int}>  $durees
     */
    public function saveTimings(Project $project, User $user, array $durees): void
    {
        $ids = $this->questionnaire->questionsFor($project)->pluck('id')->all();

        DB::transaction(function () use ($durees, $ids, $user) {
            foreach ($durees as $i => $d) {
                if (! in_array($d['question_id'], $ids, true)) {
                    throw ValidationException::withMessages([
                        "timings.{$i}.question_id" => "Cette question n'appartient pas au projet.",
                    ]);
                }
                $secondes = max(self::DUREE_MIN, min(self::DUREE_MAX, (int) (round($d['seconds'] / 5) * 5)));
                QuestionTiming::query()->updateOrCreate(
                    ['user_id' => $user->id, 'question_id' => $d['question_id']],
                    ['company_id' => $user->company_id, 'seconds' => $secondes],
                );
            }
        });
    }

    public function resetTimings(Project $project, User $user): void
    {
        QuestionTiming::query()
            ->where('user_id', $user->id)
            ->whereIn('question_id', $this->questionnaire->questionsFor($project)->pluck('id'))
            ->delete();
    }

    public function current(Prospect $prospect): ?Interview
    {
        return Interview::query()->open()->where('prospect_id', $prospect->id)->with('user')->latest('started_at')->first();
    }

    /**
     * Démarre, ou reprend celui que l'utilisateur a déjà ouvert. Un seul
     * entretien ouvert par prospect, tous auteurs confondus (décision n°5).
     *
     * @return array{Interview, bool} l'entretien, et `true` s'il vient d'être créé
     */
    public function start(Prospect $prospect, User $user): array
    {
        $plan = $this->plan($prospect->project, $user);
        if ($plan['questions']->isEmpty()) {
            throw InterviewRefused::emptyQuestionnaire();
        }

        return DB::transaction(function () use ($prospect, $user, $plan) {
            // Verrou sur le prospect : deux démarrages simultanés ne créent
            // pas deux entretiens.
            Prospect::query()->whereKey($prospect->id)->lockForUpdate()->first();

            $ouvert = $this->current($prospect);
            if ($ouvert !== null) {
                if ($ouvert->user_id === $user->id) {
                    return [$ouvert, false];
                }
                throw InterviewRefused::alreadyOpen($ouvert->user->name ?? 'un autre utilisateur', $ouvert->started_at->toIso8601String());
            }

            $entretien = Interview::query()->create([
                'company_id' => $prospect->company_id,
                'project_id' => $prospect->project_id,
                'prospect_id' => $prospect->id,
                'user_id' => $user->id,
                'started_at' => now(),
                'planned_seconds' => $plan['totals']['my_seconds'],
                'current_question_id' => $plan['questions']->first()->id,
                'answered_count' => $this->repondues($prospect),
            ]);

            return [$entretien->load('user'), true];
        });
    }

    /**
     * Enregistre une réponse, validée selon son type, sans la règle
     * obligatoire. `null` efface la réponse.
     */
    public function answer(Interview $entretien, Question $question, mixed $valeur): Interview
    {
        $this->assertOuvert($entretien);
        if ($question->project_id !== $entretien->project_id) {
            throw ValidationException::withMessages(['value' => "Cette question n'appartient pas au questionnaire de l'entretien."]);
        }

        $regles = $this->regles->forQuestion($question);
        $regles[0] = 'nullable';
        $messages = [];
        foreach ($this->regles->messages(collect([$question])) as $cle => $texte) {
            $messages[str_replace("answers.{$question->id}", 'value', $cle)] = $texte;
        }
        $valide = Validator::make(['value' => $valeur], ['value' => $regles], $messages)->validate();
        $valeur = $this->regles->cast($question, $valide['value'] ?? null);

        return DB::transaction(function () use ($entretien, $question, $valeur) {
            $prospect = $entretien->prospect;
            if ($valeur === null) {
                ProspectAnswer::query()->where('prospect_id', $prospect->id)->where('question_id', $question->id)->delete();
            } else {
                ProspectAnswer::query()->updateOrCreate(
                    ['prospect_id' => $prospect->id, 'question_id' => $question->id],
                    ['company_id' => $prospect->company_id, 'value' => $valeur],
                );
            }

            $entretien->update([
                'current_question_id' => $question->id,
                'answered_count' => $this->repondues($prospect),
            ]);

            return $entretien->fresh(['user']) ?? $entretien;
        });
    }

    /**
     * Clôt l'entretien si toutes les questions obligatoires ont une réponse.
     */
    public function finish(Interview $entretien): Interview
    {
        $this->assertOuvert($entretien);
        $prospect = $entretien->prospect;
        $questions = $this->questionnaire->questionsFor($prospect->project);
        $repondues = ProspectAnswer::query()->where('prospect_id', $prospect->id)->pluck('question_id')->all();

        $manquantes = [];
        foreach ($questions as $q) {
            if ($q->required && ! in_array($q->id, $repondues, true)) {
                $manquantes["answers.{$q->id}"] = "« {$q->label} » est obligatoire.";
            }
        }
        if ($manquantes !== []) {
            throw ValidationException::withMessages($manquantes);
        }

        return DB::transaction(function () use ($entretien, $prospect) {
            $entretien->update(['finished_at' => now(), 'answered_count' => $this->repondues($prospect)]);
            $this->journal->interviewCompleted(
                $prospect,
                (int) $entretien->started_at->diffInSeconds(now(), true),
                $entretien->planned_seconds,
                $entretien->answered_count,
            );

            return $entretien->fresh(['user']) ?? $entretien;
        });
    }

    private function assertOuvert(Interview $entretien): void
    {
        if ($entretien->status() !== 'open') {
            throw InterviewRefused::closed();
        }
    }

    private function repondues(Prospect $prospect): int
    {
        return ProspectAnswer::query()->where('prospect_id', $prospect->id)
            ->whereIn('question_id', Question::query()->where('project_id', $prospect->project_id)->select('id'))
            ->count();
    }
}
