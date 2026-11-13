<?php

namespace App\Enums;

/**
 * Événements de configuration d'un projet.
 *
 * Distincts d'`ActivityType`, qui décrit ce qui arrive à un prospect. Ceux-ci
 * décrivent ce qui arrive au cadre : le pipeline, le questionnaire, l'équipe,
 * le statut du projet.
 *
 * Le partage d'un seul enum avait été écarté : les deux journaux se lisent sur
 * des écrans différents, et un type sans objet dans l'un des deux contextes
 * finirait par y être écrit par erreur.
 */
enum ProjectActivityType: string
{
    case ProjectCreated = 'project_created';
    case ProjectUpdated = 'project_updated';
    case StatusChanged = 'status_changed';

    case StageAdded = 'stage_added';
    case StageUpdated = 'stage_updated';
    case StageRemoved = 'stage_removed';
    case StagesReordered = 'stages_reordered';

    case QuestionAdded = 'question_added';
    case QuestionUpdated = 'question_updated';
    case QuestionRemoved = 'question_removed';

    case MembersChanged = 'members_changed';

    public function label(): string
    {
        return match ($this) {
            self::ProjectCreated => 'Projet créé',
            self::ProjectUpdated => 'Projet modifié',
            self::StatusChanged => 'Statut modifié',

            self::StageAdded => 'Étape ajoutée',
            self::StageUpdated => 'Étape modifiée',
            self::StageRemoved => 'Étape retirée',
            self::StagesReordered => 'Pipeline réordonné',

            self::QuestionAdded => 'Question ajoutée',
            self::QuestionUpdated => 'Question modifiée',
            self::QuestionRemoved => 'Question retirée',

            self::MembersChanged => 'Équipe modifiée',
        };
    }

    /**
     * Événement qui change le sens des données déjà saisies.
     *
     * Renommer une étape ou retirer une question ne modifie aucune ligne, mais
     * altère la lecture de tout l'historique : un rapport comparé d'un
     * trimestre à l'autre devient faux sans que rien ne le signale. Ces
     * événements-là se signalent à l'écran.
     */
    public function reinterpreteLePasse(): bool
    {
        return in_array($this, [
            self::StageUpdated,
            self::StageRemoved,
            self::QuestionUpdated,
            self::QuestionRemoved,
        ], true);
    }
}
