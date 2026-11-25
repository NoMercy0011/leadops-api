<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entretien guidé avec un prospect — lot 13.
 *
 * Ouvert tant que `finished_at` est nul et qu'il date de moins de 24 h
 * (hypothèse H-2) ; abandonné au-delà. Le serveur tient l'heure de début :
 * le minuteur de l'écran se recalcule depuis elle, un rechargement ne le
 * remet pas à zéro.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int $prospect_id
 * @property int|null $user_id
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $finished_at
 * @property int $planned_seconds
 * @property int|null $current_question_id
 * @property int $answered_count
 */
#[Fillable(['company_id', 'project_id', 'prospect_id', 'user_id', 'started_at', 'finished_at', 'planned_seconds', 'current_question_id', 'answered_count'])]
class Interview extends Model
{
    use BelongsToCompany;

    public const HEURES_AVANT_ABANDON = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'planned_seconds' => 'integer',
            'answered_count' => 'integer',
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('finished_at')
            ->where('started_at', '>', now()->subHours(self::HEURES_AVANT_ABANDON));
    }

    public function status(): string
    {
        if ($this->finished_at !== null) {
            return 'finished';
        }

        return $this->started_at->lte(now()->subHours(self::HEURES_AVANT_ABANDON)) ? 'abandoned' : 'open';
    }
}
