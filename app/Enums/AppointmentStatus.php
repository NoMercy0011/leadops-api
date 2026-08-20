<?php

namespace App\Enums;

/**
 * Les quatre statuts de rendez-vous de l'esquisse (§7).
 *
 * « Annulé » et « Absent » sont distincts et doivent le rester : le premier est
 * un rendez-vous décommandé à l'avance, le second un prospect qui ne s'est pas
 * présenté. Les confondre effacerait un signal commercial fort — un taux
 * d'absence élevé se traite autrement qu'un taux d'annulation.
 */
enum AppointmentStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planifié',
            self::Completed => 'Réalisé',
            self::Cancelled => 'Annulé',
            self::NoShow => 'Absent',
        };
    }

    /**
     * Le rendez-vous est-il encore à honorer ?
     *
     * Sert à composer la liste « à venir » et à décider si un rendez-vous doit
     * apparaître comme en retard une fois son heure passée.
     */
    public function isOpen(): bool
    {
        return $this === self::Planned;
    }

    /**
     * Statuts qui closent un rendez-vous, quelle qu'en soit l'issue.
     *
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::Completed, self::Cancelled, self::NoShow];
    }
}
