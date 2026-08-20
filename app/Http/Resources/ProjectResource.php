<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'product' => $this->product,
            'target' => $this->target,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'accepts_new_prospects' => $this->status->acceptsNewProspects(),

            'variant' => new VariantResource($this->whenLoaded('variant')),
            'stages' => PipelineStageResource::collection($this->whenLoaded('stages')),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'members_count' => $this->whenCounted('members'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
