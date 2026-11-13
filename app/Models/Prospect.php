<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Scopes\NonArchivedScope;
use App\Support\TeamResolver;
use Carbon\CarbonInterface;
use Database\Factories\ProspectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $stage_id
 * @property int|null $assigned_user_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $phone_normalized
 * @property string|null $email_normalized
 * @property string|null $address
 * @property string|null $source
 * @property string|null $notes
 * @property CarbonInterface|null $next_action_at
 * @property string|null $next_action_note
 * @property CarbonInterface|null $qualified_at
 * @property CarbonInterface|null $converted_at
 * @property CarbonInterface|null $lost_at
 * @property CarbonInterface|null $archived_at
 * @property CarbonInterface|null $erased_at
 * @property PipelineStage $stage
 * @property Project $project
 * @property User|null $assignedUser
 */
#[Fillable([
    'company_id', 'project_id', 'stage_id', 'assigned_user_id',
    'first_name', 'last_name', 'company_name', 'phone', 'email',
    'address', 'source', 'notes', 'next_action_at', 'next_action_note',
])]
class Prospect extends Model
{
    /** @use HasFactory<ProspectFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_action_at' => 'datetime',
            'qualified_at' => 'datetime',
            'converted_at' => 'datetime',
            'lost_at' => 'datetime',
            'archived_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new NonArchivedScope);
    }

    /**
     * Résolution de route sans le filtre d'archivage.
     *
     * Sans cette dérogation, la fiche d'un prospect archivé répondrait 404 —
     * or l'archivage le range, il ne le cache pas : il doit rester consultable
     * et pouvoir être désarchivé depuis sa propre fiche.
     *
     * Le cloisonnement par entreprise, lui, continue de s'appliquer : c'est
     * `CompanyScope`, et il n'est pas retiré ici.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::query()
            ->withoutGlobalScope(NonArchivedScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * Inclut les prospects archivés.
     *
     * Nom délibérément explicite : chaque appel est une dérogation au
     * cloisonnement d'archivage et doit sauter aux yeux en revue de code.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithArchived(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NonArchivedScope::class);
    }

    /**
     * Ne retient que les prospects archivés.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnlyArchived(Builder $query): Builder
    {
        return $query
            ->withoutGlobalScope(NonArchivedScope::class)
            ->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Données personnelles effacées (lot 9, règle R4) : la fiche ne garde que
     * ce qui sert aux statistiques, et n'accepte plus d'écriture.
     */
    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<ProspectActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProspectActivity::class)->latest('occurred_at');
    }

    public function fullName(): string
    {
        if ($this->isErased()) {
            return 'Prospect effacé';
        }

        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /**
     * Prospects visibles par cet utilisateur, en surcouche du cloisonnement
     * tenant déjà appliqué par CompanyScope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if (! TeamResolver::isTeamBounded($viewer)) {
            return $query;
        }

        $ids = TeamResolver::scopeUserIds($viewer);

        // Les prospects non affectés restent visibles de l'équipe : ce sont
        // ceux qu'un manager doit pouvoir distribuer, et les masquer les
        // rendrait introuvables jusqu'à ce que quelqu'un les réclame.
        //
        // Pour un manager, seulement dans les projets de son équipe (lot 9,
        // règle R6) : sans cette borne, il voyait et archivait les
        // non-affectés de toute l'entreprise.
        return $query->where(
            fn (Builder $q) => $q
                ->whereIn('assigned_user_id', $ids)
                ->orWhere(fn (Builder $nonAffectes) => $nonAffectes
                    ->whereNull('assigned_user_id')
                    ->when(
                        $viewer->isManager(),
                        fn (Builder $borne) => $borne->whereIn(
                            'project_id',
                            Project::query()->visibleTo($viewer)->select('projects.id'),
                        ),
                    ))
        );
    }

    /**
     * Doublons probables du même contact **dans le même projet**.
     *
     * Le rapprochement inter-projets est volontairement absent : l'esquisse
     * admet qu'une même personne existe dans plusieurs projets, et le signaler
     * là serait un faux positif systématique.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePotentialDuplicatesOf(
        Builder $query,
        int $projectId,
        ?string $phoneNormalized,
        ?string $emailNormalized,
    ): Builder {
        return $query
            ->where('project_id', $projectId)
            ->where(function (Builder $q) use ($phoneNormalized, $emailNormalized) {
                // `whereRaw('1 = 0')` amorce la disjonction : sans amorce, une
                // recherche sans téléphone ni email renverrait tout le projet.
                $q->whereRaw('1 = 0');

                if ($phoneNormalized !== null) {
                    $q->orWhere('phone_normalized', $phoneNormalized);
                }

                if ($emailNormalized !== null) {
                    $q->orWhere('email_normalized', $emailNormalized);
                }
            });
    }
}
