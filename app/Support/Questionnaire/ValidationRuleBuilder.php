<?php

namespace App\Support\Questionnaire;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Traduit une définition de question en règles de validation Laravel.
 *
 * C'est la couture n°4 du §3.8 du plan, et le cœur de l'invariant n°3 : il
 * n'existe aucune règle de validation statique par champ, puisqu'aucun champ
 * n'est connu à l'écriture du code. La validation est **dérivée du type à
 * l'exécution**, et cette classe est le seul endroit qui connaît la
 * correspondance type → règle.
 *
 * Une revue de code qui trouve un `match ($question->type)` hors d'ici doit
 * être refusée : la logique se dupliquerait, et les deux copies divergeraient
 * au premier type ajouté.
 */
final class ValidationRuleBuilder
{
    /**
     * Règles pour un jeu de réponses, indexées par identifiant de question.
     *
     * La clé de sortie est `answers.{id}` : le formulaire envoie un tableau
     * associatif, ce qui évite d'avoir à deviner un ordre.
     *
     * @param  Collection<int, Question>  $questions
     * @return array<string, list<mixed>>
     */
    public function forQuestions(Collection $questions): array
    {
        $regles = [];

        foreach ($questions as $question) {
            $cle = "answers.{$question->id}";

            $regles[$cle] = $this->forQuestion($question);

            // Un choix multiple valide chaque élément séparément : la règle
            // posée sur le tableau lui-même ne dit rien de son contenu.
            if ($question->type->isMultiValue()) {
                $regles["{$cle}.*"] = [
                    Rule::in($question->options->pluck('value')->all()),
                ];
            }
        }

        return $regles;
    }

    /**
     * @return list<mixed>
     */
    public function forQuestion(Question $question): array
    {
        $regles = [$question->required ? 'required' : 'nullable'];

        return [...$regles, ...$this->rulesForType($question)];
    }

    /**
     * @return list<mixed>
     */
    private function rulesForType(Question $question): array
    {
        return match ($question->type) {
            QuestionType::Text => ['string', 'max:255'],
            QuestionType::LongText => ['string', 'max:5000'],
            QuestionType::Number => ['numeric'],
            QuestionType::Email => ['email', 'max:255'],

            // Volontairement permissif : le numéro est normalisé en aval par
            // ContactNormalizer, et refuser ici un format local que
            // libphonenumber saurait interpréter bloquerait une saisie valide.
            QuestionType::Phone => ['string', 'max:50'],

            QuestionType::Date => ['date'],

            // Les trois types à options valident contre les valeurs déclarées,
            // pas contre les libellés : renommer une option ne doit pas
            // invalider les réponses déjà enregistrées.
            QuestionType::Dropdown,
            QuestionType::SingleChoice => [Rule::in($question->options->pluck('value')->all())],

            QuestionType::MultipleChoice => ['array'],

            QuestionType::YesNo,
            QuestionType::Checkbox => ['boolean'],
        };
    }

    /**
     * Messages d'erreur portant le libellé de la question.
     *
     * Sans eux, Laravel dirait « Le champ answers.42 est obligatoire », ce qui
     * n'a aucun sens pour l'utilisateur : la question s'appelle « Âge », pas
     * « answers.42 ».
     *
     * @param  Collection<int, Question>  $questions
     * @return array<string, string>
     */
    public function messages(Collection $questions): array
    {
        $messages = [];

        foreach ($questions as $question) {
            $cle = "answers.{$question->id}";

            $messages["{$cle}.required"] = "« {$question->label} » est obligatoire.";
            $messages["{$cle}.in"] = "La réponse à « {$question->label} » ne fait pas partie des choix proposés.";
            $messages["{$cle}.*.in"] = "Un des choix retenus pour « {$question->label} » n'est pas proposé.";
            $messages["{$cle}.email"] = "« {$question->label} » doit être une adresse email valide.";
            $messages["{$cle}.numeric"] = "« {$question->label} » doit être un nombre.";
            $messages["{$cle}.date"] = "« {$question->label} » doit être une date.";
            $messages["{$cle}.boolean"] = "« {$question->label} » attend oui ou non.";
        }

        return $messages;
    }

    /**
     * Normalise une valeur validée avant stockage.
     *
     * Un formulaire HTML transmet tout en chaînes : sans cette conversion, un
     * nombre serait stocké comme `"42"` et une case cochée comme `"on"`, et le
     * front devrait deviner le type à la relecture.
     */
    public function cast(Question $question, mixed $valeur): mixed
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        return match ($question->type->storedAs()) {
            'number' => is_numeric($valeur) ? $valeur + 0 : null,
            'boolean' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'array' => array_values((array) $valeur),
            default => (string) $valeur,
        };
    }
}
