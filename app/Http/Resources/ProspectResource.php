<?php

namespace App\Http\Resources;

use App\Models\Prospect;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prospect
 */
class ProspectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'stage_id' => $this->stage_id,
            'assigned_user_id' => $this->assigned_user_id,

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'company_name' => $this->company_name,

            // Les valeurs saisies sont renvoyées telles quelles ; les colonnes
            // normalisées restent internes. Elles servent au rapprochement, pas
            // à l'affichage : montrer « +261341234567 » là où l'utilisateur a
            // tapé « 034 12 345 67 » le déroute sans rien lui apporter.
            'phone' => $this->phone,
            'email' => $this->email,

            'address' => $this->address,
            'source' => $this->source,
            'notes' => $this->notes,

            'next_action_at' => $this->next_action_at?->toIso8601String(),
            'next_action_note' => $this->next_action_note,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->isArchived(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'lost_at' => $this->lost_at?->toIso8601String(),

            // Dérivée du journal, jamais stockée — le plan interdit les
            // colonnes dénormalisées qu'il faudrait rattraper ensuite.
            'last_interaction_at' => $this->whenHas(
                'activities_max_occurred_at',
                fn (?string $valeur) => $valeur,
            ),

            'stage' => new PipelineStageResource($this->whenLoaded('stage')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'assigned_user' => new UserResource($this->whenLoaded('assignedUser')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
