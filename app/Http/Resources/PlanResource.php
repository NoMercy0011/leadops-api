<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,

            // NULL est transmis tel quel et signifie « illimité ». Le convertir
            // en 0 ou en -1 côté API obligerait le front à connaître la
            // convention ; `null` se lit sans ambiguïté.
            'max_projects' => $this->max_projects,
            'max_users' => $this->max_users,
            'max_prospects' => $this->max_prospects,
        ];
    }
}
