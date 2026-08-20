<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Models\Concerns\BelongsToCompany;
use App\Support\TeamResolver;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $prospect_id
 * @property int|null $user_id
 * @property CarbonInterface $scheduled_at
 * @property int $duration_minutes
 * @property AppointmentType $type
 * @property AppointmentStatus $status
 * @property string|null $location
 * @property string|null $notes
 * @property Prospect $prospect
 * @property Project $project
 * @property User|null $user
 */
#[Fillable([
    'company_id', 'project_id', 'prospect_id', 'user_id',
    'scheduled_at', 'duration_minutes', 'type', 'status', 'location', 'notes',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * Le défaut est porté par le modèle autant que par la colonne : un défaut
     * de base n'est appliqué qu'à l'insertion et laisserait `status` nul en
     * mémoire, faisant échouer toute sérialisation faite dans la foulée.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AppointmentStatus::Planned->value,
        'duration_minutes' => 60,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'type' => AppointmentType::class,
            'status' => AppointmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function endsAt(): CarbonInterface
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Rendez-vous encore planifié dont l'heure est passée.
     *
     * Ce n'est pas un statut supplémentaire mais un état calculé : le
     * commercial doit le clore en indiquant s'il a eu lieu, a été annulé, ou
     * si le prospect ne s'est pas présenté.
     */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->scheduled_at->isPast();
    }

    /**
     * Rendez-vous visibles par cet utilisateur, après le cloisonnement tenant.
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

        // Les rendez-vous sans commercial restent visibles : ce sont ceux qu'un
        // manager doit pouvoir attribuer, et les masquer les rendrait
        // introuvables.
        return $query->where(
            fn (Builder $q) => $q
                ->whereIn('user_id', $ids)
                ->orWhereNull('user_id')
        );
    }

    /**
     * Restreint à une plage temporelle, bornes UTC comprises.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBetween(Builder $query, CarbonInterface $debut, CarbonInterface $fin): Builder
    {
        return $query->whereBetween('scheduled_at', [$debut, $fin]);
    }
}
