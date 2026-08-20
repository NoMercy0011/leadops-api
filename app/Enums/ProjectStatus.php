<?php

namespace App\Enums;

/**
 * Statut d'un projet — §3 de l'esquisse.
 *
 * À ne pas confondre avec les étapes de pipeline : celles-ci décrivent
 * l'avancement d'un prospect et sont configurables par le client, alors que
 * ces trois valeurs décrivent la vie du projet lui-même et sont fixes.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Completed => 'Terminé',
        };
    }

    /**
     * Un projet terminé ou suspendu n'accepte plus de nouveaux prospects.
     * La consultation et le reporting restent ouverts — même asymétrie que
     * pour les abonnements.
     */
    public function acceptsNewProspects(): bool
    {
        return $this === self::Active;
    }
}
