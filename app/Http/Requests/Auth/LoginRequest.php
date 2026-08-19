<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],

            // Nom du jeton Sanctum : permet de révoquer un appareil précis
            // sans déconnecter les autres sessions de l'utilisateur.
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
