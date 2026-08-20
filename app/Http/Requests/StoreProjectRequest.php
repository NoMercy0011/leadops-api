<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                // Unicité par entreprise : deux clients peuvent avoir un projet
                // « Assurance Santé » sans que cela pose problème.
                Rule::unique('projects', 'name')->where('company_id', $companyId),
            ],

            // La variante doit appartenir à la même entreprise. La contrainte
            // est portée par la règle plutôt que laissée à la clé étrangère :
            // celle-ci accepterait n'importe quelle variante existante.
            'variant_id' => [
                'nullable', 'integer',
                Rule::exists('variants', 'id')->where('company_id', $companyId),
            ],

            'product' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],

            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Un projet porte déjà ce nom dans votre entreprise.',
            'variant_id.exists' => "Cette variante n'appartient pas à votre entreprise.",
            'member_ids.*.exists' => "Un des commerciaux désignés n'appartient pas à votre entreprise.",
        ];
    }
}
