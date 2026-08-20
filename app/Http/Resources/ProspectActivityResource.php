<?php

namespace App\Http\Resources;

use App\Models\ProspectActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProspectActivity
 */
class ProspectActivityResource extends JsonResource
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
            'is_interaction' => $this->type->isInteraction(),
            'payload' => $this->payload,

            // `occurred_at` et non `created_at` : un appel passé hier peut être
            // enregistré aujourd'hui, et c'est la date de l'événement qui fait
            // foi dans la chronologie.
            'occurred_at' => $this->occurred_at->toIso8601String(),

            // L'auteur peut être absent — import automatisé, ou compte
            // supprimé depuis. Le front affiche alors « Système ».
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
