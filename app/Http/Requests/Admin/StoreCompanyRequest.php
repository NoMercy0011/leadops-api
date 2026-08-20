<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('companies', 'slug')],
            'timezone' => ['required', 'string', 'timezone'],
            'default_country_code' => ['required', 'string', 'size:2'],

            // L'abonnement est créé dans la foulée : une entreprise sans plan
            // ne peut rien créer, l'état serait donc inutilisable.
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Cet identifiant est déjà utilisé par une autre entreprise.',
            'expires_at.after' => "La date d'expiration doit être postérieure à aujourd'hui.",
        ];
    }
}
