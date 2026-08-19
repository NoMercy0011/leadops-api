<?php

namespace App\Enums;

/**
 * Les quatre rôles de la plateforme.
 *
 * Super Admin est le seul à sortir du périmètre d'une entreprise ; les trois
 * autres sont des restrictions successives à l'intérieur d'une entreprise.
 * L'autorisation se compose donc en deux couches — scope tenant, puis scope
 * rôle — et la couche tenant s'applique toujours en premier.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AdminClient = 'admin_client';
    case Manager = 'manager';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminClient => 'Admin Client',
            self::Manager => 'Manager',
            self::Commercial => 'Commercial',
        };
    }

    /**
     * Un Super Admin n'appartient à aucune entreprise : son company_id est nul.
     */
    public function isTenantScoped(): bool
    {
        return $this !== self::SuperAdmin;
    }

    /**
     * Rôles assignables depuis l'espace d'une entreprise cliente.
     *
     * @return array<int, self>
     */
    public static function assignableWithinCompany(): array
    {
        return [self::AdminClient, self::Manager, self::Commercial];
    }
}
