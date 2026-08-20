<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attache le contexte tenant à toutes les lignes de journal d'une requête.
 *
 * Sur un produit mono-client, une trace d'erreur suffit. En multi-entreprises,
 * la première question posée devant une anomalie est « chez quel client ? » —
 * et sans cette réponse, l'exploitation consiste à recouper des horodatages à
 * la main.
 *
 * L'identifiant de requête permet de relier entre elles les lignes d'un même
 * appel, y compris quand plusieurs utilisateurs déclenchent la même erreur en
 * même temps.
 *
 * Aucune donnée personnelle n'est journalisée : des identifiants, jamais un
 * nom, une adresse ni un numéro. Les journaux sont conservés longtemps et
 * consultés par des tiers ; y recopier des coordonnées de prospects en
 * multiplierait les copies sans nécessité.
 */
class AddLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        Log::withContext(array_filter([
            'request_id' => (string) Str::uuid(),
            'company_id' => $user instanceof User ? $user->company_id : null,
            'user_id' => $user?->getAuthIdentifier(),
            'role' => $user instanceof User ? $user->role->value : null,
            'route' => $request->route()?->getName(),
        ], fn ($valeur) => $valeur !== null));

        return $next($request);
    }
}
