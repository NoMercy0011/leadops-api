<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponse d'un prospect à une question de son projet.
 *
 * @property int $id
 * @property int $company_id
 * @property int $prospect_id
 * @property int $question_id
 * @property mixed $value
 * @property string|null $value_text
 */
#[Fillable(['company_id', 'prospect_id', 'question_id', 'value'])]
class ProspectAnswer extends Model
{
    use BelongsToCompany;

    // `value_text` est volontairement absente des attributs assignables : la
    // colonne est générée en base à partir de `value`, et toute tentative de
    // l'écrire depuis PHP produirait une erreur SQL.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Le stockage est générique : la valeur peut être une chaîne, un
            // nombre, un booléen ou un tableau selon le type de la question.
            'value' => 'json',
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
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
