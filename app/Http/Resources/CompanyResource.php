<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
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
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'default_country_code' => $this->default_country_code,

            // Le front s'en sert pour griser des boutons et afficher un
            // bandeau. Il ne fait jamais autorité : l'API tranche.
            'allows_writes' => $this->allowsWrites(),

            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
        ];
    }
}
