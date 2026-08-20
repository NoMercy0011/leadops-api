<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\ProspectActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du journal d'un prospect.
 *
 * Les activités ne sont jamais modifiées ni supprimées : un historique
 * réécrivable ne vaut rien comme trace, et les KPIs du lot 7 s'appuient dessus.
 *
 * @property int $id
 * @property int $company_id
 * @property int $prospect_id
 * @property int|null $user_id
 * @property ActivityType $type
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $occurred_at
 */
#[Fillable(['company_id', 'prospect_id', 'user_id', 'type', 'payload', 'occurred_at'])]
class ProspectActivity extends Model
{
    /** @use HasFactory<ProspectActivityFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
