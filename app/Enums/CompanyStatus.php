<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspendue',
        };
    }
}
