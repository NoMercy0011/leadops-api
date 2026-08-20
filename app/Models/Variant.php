<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variante de produit ou d'offre, propre à une entreprise.
 *
 * Sert d'axe de reporting (§11 de l'esquisse), d'où la table de référence
 * plutôt qu'un champ texte sur le projet — décision §10.4 du plan.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $position
 */
#[Fillable(['name', 'position'])]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
