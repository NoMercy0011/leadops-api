<?php

namespace App\Models;

use App\Enums\StageColor;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape de pipeline, rattachée à un projet précis.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property string $name
 * @property int $position
 * @property bool $is_won
 * @property bool $is_lost
 * @property StageColor $color
 */
// `company_id` est explicitement assignable, contrairement aux autres modèles
// cloisonnés : l'entreprise d'une étape est celle de son *projet*, pas celle de
// l'utilisateur qui la crée. S'en remettre à l'observer du trait rattacherait
// l'étape au mauvais tenant dès qu'un contexte sans utilisateur intervient —
// service appelé directement, seeder, commande console.
#[Fillable(['company_id', 'project_id', 'name', 'position', 'is_won', 'is_lost', 'color'])]
class PipelineStage extends Model
{
    /** @use HasFactory<PipelineStageFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_won' => false,
        'is_lost' => false,
        'color' => StageColor::Slate->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'color' => StageColor::class,
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
     * Étape terminale, gagnée ou perdue.
     *
     * C'est le seul moyen autorisé de reconnaître une fin de parcours : le
     * libellé est saisi par le client, renommable et traduisible, donc jamais
     * testé — invariant n°2.
     */
    public function isTerminal(): bool
    {
        return $this->is_won || $this->is_lost;
    }
}
