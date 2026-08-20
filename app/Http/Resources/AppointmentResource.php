<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'prospect_id' => $this->prospect_id,
            'user_id' => $this->user_id,

            // ISO 8601 en UTC, comme toute date du produit. Le front convertit
            // dans le fuseau de l'entreprise à l'affichage — décision §10.5.
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'ends_at' => $this->endsAt()->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // État calculé, non stocké : un rendez-vous encore planifié dont
            // l'heure est passée appelle une action, sans être un statut de
            // plus à maintenir.
            'is_overdue' => $this->isOverdue(),

            'location' => $this->location,
            'notes' => $this->notes,

            'prospect' => new ProspectResource($this->whenLoaded('prospect')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
