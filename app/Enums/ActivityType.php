<?php

namespace App\Enums;

/**
 * Types d'événements du journal d'un prospect.
 *
 * Contrairement aux étapes de pipeline, ces valeurs sont bien un enum : elles
 * décrivent la mécanique du produit, pas la stratégie commerciale du client,
 * et ne sont donc pas configurables.
 */
enum ActivityType: string
{
    case Created = 'created';
    case StageChanged = 'stage_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case NoteAdded = 'note_added';
    case Imported = 'imported';
    case NextActionPlanned = 'next_action_planned';
    case FieldsUpdated = 'fields_updated';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Prospect créé',
            self::StageChanged => "Changement d'étape",
            self::Assigned => 'Affectation',
            self::Unassigned => 'Désaffectation',
            self::NoteAdded => 'Note ajoutée',
            self::Imported => 'Importé',
            self::NextActionPlanned => 'Relance planifiée',
            self::FieldsUpdated => 'Fiche modifiée',
        };
    }

    /**
     * Compte-t-il comme une interaction commerciale ?
     *
     * Sert au calcul de « dernière interaction » : une modification de fiche ou
     * un import ne sont pas des échanges avec le prospect et gonfleraient
     * artificiellement l'indicateur de suivi.
     */
    public function isInteraction(): bool
    {
        return match ($this) {
            self::StageChanged, self::NoteAdded, self::NextActionPlanned => true,
            default => false,
        };
    }
}
