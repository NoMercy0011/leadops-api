<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les écritures d'une entreprise suspendue ou dont l'abonnement a
 * expiré, en laissant la lecture ouverte.
 *
 * Cette asymétrie est volontaire : une entreprise qui ne paie plus doit pouvoir
 * consulter et exporter ses données. Couper l'accès en lecture reviendrait à
 * prendre ses données en otage.
 */
class EnsureSubscriptionAllowsWrites
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        $user = $request->user();

        // Le Super Admin n'a pas d'entreprise : c'est justement lui qui
        // réactive les abonnements suspendus, il ne peut pas être bloqué ici.
        if (! $user instanceof User || $user->company_id === null) {
            return $next($request);
        }

        $company = $user->company;

        if ($company !== null && ! $company->allowsWrites()) {
            return response()->json([
                'code' => 'subscription.writes_blocked',
                'message' => "L'abonnement de votre entreprise ne permet plus d'enregistrer de modifications. La consultation et l'export restent disponibles.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
