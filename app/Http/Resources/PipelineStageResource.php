<?php

namespace App\Http\Resources;

use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PipelineStage
 */
class PipelineStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'position' => $this->position,

            // La sémantique terminale voyage par ces deux drapeaux, jamais par
            // le libellé — invariant n°2. Le front ne doit rien déduire du nom.
            'is_won' => $this->is_won,
            'is_lost' => $this->is_lost,

            // Un nom de couleur, pas un code hexadécimal : le rendu appartient
            // à la charte côté front, et transmettre un #RRGGBB figerait la
            // palette en base.
            'color' => $this->color->value,
        ];
    }
}
