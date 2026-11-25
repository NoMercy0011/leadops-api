<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Durée personnelle d'un commercial pour une question — lot 13, règle R2.
 *
 * Issue d'une simulation ; ne s'applique qu'aux entretiens de cet
 * utilisateur. Le questionnaire commun garde sa durée prévue.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $question_id
 * @property int $seconds
 */
#[Fillable(['company_id', 'user_id', 'question_id', 'seconds'])]
class QuestionTiming extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['seconds' => 'integer'];
    }
}
