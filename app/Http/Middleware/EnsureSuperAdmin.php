<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve le back-office au Super Admin.
 *
 * C'est une barrière de groupe, doublée par les Policies au niveau de chaque
 * objet. La redondance est voulue : le back-office est le seul endroit du code
 * qui sort du cloisonnement par entreprise, donc le seul où une erreur
 * d'autorisation expose les données de tous les clients à la fois.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            // 403 et non 404 : l'existence du back-office n'est pas un secret,
            // et un 404 rendrait le diagnostic incompréhensible côté client.
            return response()->json([
                'code' => 'auth.forbidden',
                'message' => 'Cette section est réservée à l\'administration de la plateforme.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
