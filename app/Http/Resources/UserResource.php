<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Contrat de sortie explicite.
     *
     * Sérialiser le modèle brut exposerait par accident toute colonne ajoutée
     * plus tard — c'est la raison d'être de cette classe.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'company_id' => $this->company_id,
            'manager_id' => $this->manager_id,
            'company' => new CompanyResource($this->whenLoaded('company')),
        ];
    }
}
