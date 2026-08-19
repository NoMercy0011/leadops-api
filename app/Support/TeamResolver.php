<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Point de résolution unique de la hiérarchie d'équipe — couture §3.8 du plan.
 *
 * La colonne `users.manager_id` ne doit être lue nulle part ailleurs. Le jour
 * où un commercial devra dépendre de plusieurs managers, ou bien où des
 * équipes nommées apparaîtront, la migration se limite à cette classe et à une
 * table : ni les contrôleurs, ni les policies, ni les requêtes ne changent.
 *
 * Une revue de code qui trouve un `manager_id` hors d'ici doit être refusée.
 */
final class TeamResolver
{
    /**
     * Identifiants des utilisateurs dont ce manager a la charge, lui compris.
     *
     * @return Collection<int, int>
     */
    public static function scopeUserIds(User $viewer): Collection
    {
        return match ($viewer->role) {
            UserRole::Manager => $viewer->subordinates()
                ->pluck('id')
                ->push($viewer->id),

            UserRole::Commercial => collect([$viewer->id]),

            // Admin Client et Super Admin ne sont pas bornés par l'équipe ;
            // leur périmètre est traité en amont, par le cloisonnement tenant.
            UserRole::AdminClient, UserRole::SuperAdmin => collect(),
        };
    }

    /**
     * Le périmètre de ce rôle est-il borné par l'équipe ?
     *
     * Renvoie false pour les rôles dont la visibilité couvre toute l'entreprise,
     * cas où `scopeUserIds` renvoie une collection vide qu'il ne faut surtout
     * pas interpréter comme « ne voit personne ».
     */
    public static function isTeamBounded(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Manager, UserRole::Commercial], true);
    }
}
