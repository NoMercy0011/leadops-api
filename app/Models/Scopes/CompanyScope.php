<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Cloisonnement des données par entreprise — l'invariant n°1 de LeadOps.
 *
 * Ce scope est appliqué par le trait BelongsToCompany, jamais à la main. Il
 * s'ajoute à toute requête d'un modèle métier, de sorte que l'oubli soit
 * impossible plutôt que rare : c'est tout l'intérêt d'un global scope face à
 * des `where('company_id', …)` répétés dans chaque contrôleur.
 *
 * Deux cas ne sont volontairement pas filtrés :
 *
 * 1. Le Super Admin, dont le périmètre est transversal par définition.
 * 2. L'absence d'utilisateur authentifié — commandes console, seeders, files
 *    d'attente. C'est le point faible assumé du dispositif : une route métier
 *    accessible sans authentification verrait toutes les entreprises. Le test
 *    RouteProtectionTest vérifie donc qu'aucune route métier n'échappe à
 *    `auth:sanctum`, et c'est lui qui referme cette porte.
 */
/**
 * @implements Scope<Model>
 */
class CompanyScope implements Scope
{
    /**
     * `covariant` reprend la signature de l'interface : le scope est appliqué à
     * des builders de sous-types de Model, pas à Model exactement.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        // `hasUser()` et non `user()` : le scope s'applique aussi au modèle
        // User, et Sanctum charge celui-ci pour résoudre le jeton. Appeler
        // `Auth::user()` ici relancerait cette résolution, qui rechargerait le
        // User, qui rappellerait le scope — récursion infinie. `hasUser()` ne
        // déclenche aucune résolution et renvoie false tant qu'elle est en
        // cours, ce qui laisse passer la requête d'authentification elle-même.
        if (! Auth::hasUser()) {
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if (! $user->role->isTenantScoped()) {
            return;
        }

        $builder->where(
            $model->getTable().'.company_id',
            $user->company_id
        );
    }
}
