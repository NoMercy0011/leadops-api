<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use App\Support\TeamResolver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $variant_id
 * @property string $name
 * @property string|null $product
 * @property string|null $target
 * @property string|null $description
 * @property ProjectStatus $status
 * @property Variant|null $variant
 */
#[Fillable(['variant_id', 'name', 'product', 'target', 'description', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * Le défaut est porté par le modèle autant que par la colonne : un défaut
     * de base n'est appliqué qu'à l'insertion et laisserait `status` nul en
     * mémoire, faisant échouer toute sérialisation faite dans la foulée.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ProjectStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('position');
    }

    /**
     * Commerciaux affectés au projet.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function wonStage(): ?PipelineStage
    {
        return $this->stages->firstWhere('is_won', true);
    }

    public function lostStage(): ?PipelineStage
    {
        return $this->stages->firstWhere('is_lost', true);
    }

    /**
     * Projets visibles par cet utilisateur, en surcouche du cloisonnement
     * tenant déjà appliqué par CompanyScope.
     *
     * Manager et commercial ne voient que les projets où ils sont affectés :
     * leur montrer l'intégralité du catalogue de l'entreprise trahirait la
     * segmentation commerciale que le produit met en place.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if (! TeamResolver::isTeamBounded($viewer)) {
            // Admin Client et Super Admin : pas de restriction supplémentaire.
            return $query;
        }

        $ids = $viewer->role === UserRole::Manager
            ? TeamResolver::scopeUserIds($viewer)
            : collect([$viewer->id]);

        return $query->whereHas(
            'members',
            fn (Builder $q) => $q->whereIn('users.id', $ids)
        );
    }
}
