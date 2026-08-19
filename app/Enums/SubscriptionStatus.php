<?php

namespace App\Enums;

/**
 * Un abonnement suspendu ou expiré bloque toute écriture mais laisse la
 * lecture ouverte : une entreprise doit pouvoir consulter et exporter ses
 * données même quand elle ne paie plus.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Expired => 'Expiré',
        };
    }

    public function allowsWrites(): bool
    {
        return $this === self::Active;
    }
}
