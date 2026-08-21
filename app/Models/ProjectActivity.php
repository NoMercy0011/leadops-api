<?php

namespace App\Models;

use App\Enums\ProjectActivityType;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property ProjectActivityType $type
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $occurred_at
 */
#[Fillable(['company_id', 'project_id', 'user_id', 'type', 'payload', 'occurred_at'])]
class ProjectActivity extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProjectActivityType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Auteur de la modification.
     *
     * Nul pour un événement sans auteur humain — un seeder, une commande
     * console — ou après la suppression du compte concerné.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
