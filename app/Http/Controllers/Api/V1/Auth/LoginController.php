<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Échange des identifiants contre un jeton Sanctum.
     *
     * Le jeton est destiné au serveur Next.js, qui le scelle dans un cookie
     * httpOnly : il ne doit jamais atteindre le JavaScript du navigateur.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        // La requête sort volontairement du cloisonnement : à ce stade aucun
        // utilisateur n'est authentifié, donc aucun périmètre d'entreprise
        // n'est connu. C'est le seul point du code où c'est légitime.
        $user = User::query()->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password')->value(), $user->password)) {
            RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

            // Message unique quel que soit le motif : distinguer « email
            // inconnu » de « mot de passe faux » révélerait quels comptes
            // existent sur la plateforme.
            throw ValidationException::withMessages([
                'email' => __('Ces identifiants ne correspondent à aucun compte.'),
            ]);
        }

        // Une entreprise suspendue bloque l'accès de ses utilisateurs. Le Super
        // Admin n'a pas d'entreprise et n'est donc pas concerné.
        if ($user->company !== null && $user->company->status === CompanyStatus::Suspended) {
            throw ValidationException::withMessages([
                'email' => __("L'accès de votre entreprise est suspendu. Contactez votre administrateur."),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $token = $user->createToken(
            $request->string('device_name', 'leadops-web')->value()
        )->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load('company.subscription.plan')),
            ],
        ]);
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('Trop de tentatives. Réessayez dans :seconds secondes.', [
                'seconds' => RateLimiter::availableIn($this->throttleKey($request)),
            ]),
        ]);
    }

    /**
     * Limitation par couple email + IP : borner sur la seule IP permettrait à
     * un attaquant de bloquer un compte en le pilonnant depuis une autre.
     */
    private function throttleKey(Request $request): string
    {
        return 'login|'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
