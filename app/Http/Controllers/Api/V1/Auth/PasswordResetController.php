<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Demande d'envoi d'un lien de réinitialisation.
     *
     * La réponse est identique que l'adresse existe ou non : renvoyer une
     * erreur pour une adresse inconnue transformerait ce point d'entrée en
     * oracle permettant d'énumérer les comptes de la plateforme.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'data' => [
                'message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.',
            ],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Un mot de passe réinitialisé invalide toutes les sessions :
                // si le compte avait été compromis, laisser vivre les jetons
                // existants annulerait l'intérêt du changement.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json([
            'data' => ['message' => 'Mot de passe réinitialisé.'],
        ]);
    }
}
