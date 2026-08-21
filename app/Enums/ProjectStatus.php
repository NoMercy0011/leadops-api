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
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Completed => 'Terminé',
            self::Archived => 'Archivé',
        };
    }

    /**
     * Un projet terminé, suspendu ou archivé n'accepte plus de nouveaux
     * prospects. La consultation et le reporting restent ouverts — même
     * asymétrie que pour les abonnements.
     */
    public function acceptsNewProspects(): bool
    {
        return $this === self::Active;
    }

    /**
     * Sorti de la vue courante.
     *
     * L'archivage remplace la suppression, qui n'existe pas : les clés
     * étrangères sont en cascade et un projet effacé emporterait ses
     * prospects et leur historique. Archiver retire le projet des listes sans
     * rien détruire, et le geste se défait.
     *
     * Distinct de « Terminé », qui dit qu'un projet est allé à son terme.
     * Un projet abandonné au bout de trois semaines n'est pas terminé, et
     * confondre les deux fausserait toute lecture du portefeuille.
     */
    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /**
     * Statuts visibles par défaut dans les listes.
     *
     * @return list<string>
     */
    public static function visibleParDefaut(): array
    {
        return array_map(
            fn (self $statut) => $statut->value,
            array_filter(self::cases(), fn (self $statut) => ! $statut->isArchived()),
        );
    }
}
