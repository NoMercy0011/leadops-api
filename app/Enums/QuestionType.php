<?php

namespace App\Enums;

/**
 * Les onze types de questions de l'esquisse (§6).
 *
 * Invariant n°3 : le questionnaire est configurable sans déploiement. Ces
 * valeurs décrivent la *mécanique* d'un champ — comment il se saisit et comment
 * il se valide — jamais son sens métier, qui appartient au libellé saisi par le
 * client. Ajouter un type est un changement de code assumé ; ajouter une
 * question ne l'est pas.
 */
enum QuestionType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case Dropdown = 'dropdown';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case YesNo = 'yes_no';
    case Checkbox = 'checkbox';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texte',
            self::LongText => 'Texte long',
            self::Number => 'Nombre',
            self::Email => 'Email',
            self::Phone => 'Téléphone',
            self::Date => 'Date',
            self::Dropdown => 'Liste déroulante',
            self::SingleChoice => 'Choix unique',
            self::MultipleChoice => 'Choix multiple',
            self::YesNo => 'Oui / Non',
            self::Checkbox => 'Case à cocher',
        };
    }

    /**
     * Le type exige-t-il une liste d'options ?
     *
     * `YesNo` n'en fait pas partie : ses deux valeurs sont fixées par le type
     * lui-même. Laisser le client les saisir ouvrirait la porte à un « oui/non »
     * à trois options, que le front ne saurait plus rendre.
     */
    public function requiresOptions(): bool
    {
        return match ($this) {
            self::Dropdown, self::SingleChoice, self::MultipleChoice => true,
            default => false,
        };
    }

    /**
     * La réponse est-elle une liste de valeurs plutôt qu'une valeur unique ?
     */
    public function isMultiValue(): bool
    {
        return $this === self::MultipleChoice;
    }

    /**
     * Type scalaire stocké dans le JSON de réponse.
     *
     * Sert au front pour rendre la valeur, et à la recherche : la colonne
     * générée qui indexe les réponses n'a de sens que sur un scalaire.
     */
    public function storedAs(): string
    {
        return match ($this) {
            self::Number => 'number',
            self::YesNo, self::Checkbox => 'boolean',
            self::MultipleChoice => 'array',
            default => 'string',
        };
    }

    /**
     * Types proposés dans le constructeur, dans l'ordre d'usage attendu.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Text, self::LongText, self::Number, self::Email,
            self::Phone, self::Date, self::Dropdown, self::SingleChoice,
            self::MultipleChoice, self::YesNo, self::Checkbox,
        ];
    }
}
