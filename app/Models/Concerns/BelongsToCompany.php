<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * À appliquer sur tout modèle métier portant une company_id.
 *
 * Le trait fait deux choses indissociables, et c'est la raison pour laquelle
 * l'attribut #[ScopedBy] seul ne suffit pas :
 *
 * - en lecture, il applique CompanyScope ;
 * - en écriture, il renseigne company_id depuis l'utilisateur authentifié.
 *
 * Sans le second volet, le scope filtrerait correctement les lectures tout en
 * laissant créer des lignes orphelines ou rattachées à la mauvaise entreprise
 * — un cloisonnement qui paraît fonctionner mais fuit à l'insertion.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $user = Auth::user();

            if ($user instanceof User && $user->company_id !== null) {
                $model->setAttribute('company_id', $user->company_id);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Sortie explicite du cloisonnement, réservée au back-office Super Admin.
     *
     * Le nom est délibérément long et explicite : un appel à cette méthode doit
     * sauter aux yeux en revue de code, car chacun est une dérogation à
     * l'invariant n°1 et doit être justifié.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAcrossAllCompanies(Builder $query): Builder
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}
