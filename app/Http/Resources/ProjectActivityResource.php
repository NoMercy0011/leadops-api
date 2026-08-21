<?php

namespace App\Http\Resources;

use App\Models\ProjectActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectActivity
 */
class ProjectActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            // Signale les événements qui changent le sens des données déjà
            // saisies — un renommage d'étape, une question retirée. L'écran
            // s'en sert pour les distinguer d'une simple modification.
            'reinterprete_le_passe' => $this->type->reinterpreteLePasse(),

            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at->toIso8601String(),

            // Absent pour un événement sans auteur humain — seeder, commande
            // console — ou après suppression du compte.
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
