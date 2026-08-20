<?php

namespace App\Http\Resources;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuestionOption
 */
class QuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // `label` s'affiche, `value` s'enregistre. Les deux sont distincts
            // pour qu'un renommage n'invalide pas les réponses existantes.
            'label' => $this->label,
            'value' => $this->value,
            'position' => $this->position,
        ];
    }
}
