<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $expires_at
 * @property Plan|null $plan
 */
#[Fillable(['company_id', 'plan_id', 'status', 'starts_at', 'expires_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Une date d'échéance dépassée vaut expiration, même si la colonne `status`
     * n'a pas encore été mise à jour par une tâche planifiée. L'état effectif
     * se calcule, il ne se lit pas seulement.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function effectiveStatus(): SubscriptionStatus
    {
        if ($this->status === SubscriptionStatus::Active && $this->hasExpired()) {
            return SubscriptionStatus::Expired;
        }

        return $this->status;
    }

    public function allowsWrites(): bool
    {
        return $this->effectiveStatus()->allowsWrites();
    }
}
