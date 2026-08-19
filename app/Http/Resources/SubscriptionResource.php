<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // `status` est la valeur stockée, `effective_status` tient compte
            // d'une échéance dépassée que la tâche planifiée n'a pas encore
            // traitée. Le front doit afficher la seconde.
            'status' => $this->status->value,
            'effective_status' => $this->effectiveStatus()->value,
            'effective_status_label' => $this->effectiveStatus()->label(),

            // `starts_at` est obligatoire en base, contrairement à `expires_at`
            // dont la nullité signifie « sans échéance ».
            'starts_at' => $this->starts_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'plan' => new PlanResource($this->whenLoaded('plan')),
        ];
    }
}
