<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    /**
     * Révoque le seul jeton ayant servi à la requête.
     *
     * Se déconnecter d'un poste ne doit pas fermer les sessions ouvertes
     * ailleurs — d'où la révocation ciblée plutôt qu'un `tokens()->delete()`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['data' => ['message' => 'Déconnexion effectuée.']]);
    }
}
