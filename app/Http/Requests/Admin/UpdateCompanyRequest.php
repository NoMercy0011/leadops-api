<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255', 'alpha_dash',
                Rule::unique('companies', 'slug')->ignore($company),
            ],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'default_country_code' => ['sometimes', 'string', 'size:2'],
        ];
    }
}
