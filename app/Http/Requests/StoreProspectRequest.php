<?php

namespace App\Http\Requests;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProspectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Prospect::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            // La règle vérifie l'appartenance à l'entreprise. La clé étrangère
            // seule accepterait n'importe quel projet existant, y compris
            // celui d'un autre client.
            'project_id' => [
                'required', 'integer',
                Rule::exists('projects', 'id')->where('company_id', $companyId),
            ],

            // Facultatif : sans étape précisée, le prospect entre à la
            // première du pipeline. L'appartenance au bon projet est vérifiée
            // par ProspectService, qui seul connaît le projet retenu.
            'stage_id' => ['sometimes', 'integer'],

            'assigned_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],

            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_action_at' => ['nullable', 'date'],
            'next_action_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.exists' => "Ce projet n'appartient pas à votre entreprise.",
            'assigned_user_id.exists' => "Ce commercial n'appartient pas à votre entreprise.",
        ];
    }
}
