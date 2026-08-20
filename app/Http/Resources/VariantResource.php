<?php

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,

            // Sert au front à prévenir avant une suppression : une variante
            // utilisée ne peut pas être supprimée.
            'projects_count' => $this->whenCounted('projects'),
        ];
    }
}
