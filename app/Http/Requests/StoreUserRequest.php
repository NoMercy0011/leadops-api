<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // Seul l'Admin Client gère les comptes de son entreprise. Un Manager
        // encadre une équipe mais ne crée pas de comptes : cela reviendrait à
        // consommer le quota utilisateurs de l'entreprise.
        return $user instanceof User && ($user->isAdminClient() || $user->isSuperAdmin());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::assignableWithinCompany())],

            // Le manager désigné doit appartenir à la même entreprise. La règle
            // s'appuie sur le modèle scopé : un identifiant d'une autre
            // entreprise est simplement introuvable, donc rejeté.
            'manager_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('company_id', $this->user()?->company_id)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.Illuminate\Validation\Rules\Enum' => 'Ce rôle ne peut pas être attribué depuis un espace entreprise.',
            'manager_id.exists' => "Le manager désigné n'appartient pas à votre entreprise.",
        ];
    }
}
