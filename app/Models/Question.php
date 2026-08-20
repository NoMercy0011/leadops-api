<?php

namespace App\Models;

use App\Enums\QuestionType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Définition d'une question de qualification, propre à un projet.
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property QuestionType $type
 * @property string $label
 * @property string|null $help
 * @property bool $required
 * @property int $position
 * @property Collection<int, QuestionOption> $options
 */
#[Fillable(['company_id', 'project_id', 'type', 'label', 'help', 'required', 'position'])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'required' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'required' => 'boolean',
            'position' => 'integer',
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
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProspectAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ProspectAnswer::class);
    }

    /**
     * Des réponses ont-elles déjà été enregistrées ?
     *
     * Conditionne deux interdits : changer le type d'une question répondue, et
     * la supprimer définitivement. Les deux détruiraient de l'information
     * déjà collectée.
     */
    public function hasAnswers(): bool
    {
        return $this->answers()->exists();
    }
}
