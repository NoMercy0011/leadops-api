<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Choix proposé pour une question à options.
 *
 * @property int $id
 * @property int $company_id
 * @property int $question_id
 * @property string $label
 * @property string $value
 * @property int $position
 */
#[Fillable(['company_id', 'question_id', 'label', 'value', 'position'])]
class QuestionOption extends Model
{
    use BelongsToCompany;

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
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
