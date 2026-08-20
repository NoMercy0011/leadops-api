<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limitation de débit de l'API.
 *
 * Le lot 1 protégeait la connexion contre le bourrage d'identifiants ; le reste
 * de l'API était ouvert. Sur un produit multi-entreprises, une boucle mal
 * écrite chez un client dégrade le service de tous les autres — la limitation
 * n'est donc pas seulement une défense contre l'abus, c'est un cloisonnement
 * de la ressource au même titre que celui des données.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Débit courant. La clé est l'utilisateur et non l'adresse IP : dans
        // une entreprise cliente, toute l'équipe partage souvent une sortie
        // Internet unique, et compter par IP pénaliserait les collègues d'un
        // utilisateur trop actif.
        RateLimiter::for('api', fn (Request $request) => $this->limite($request, 120));

        // Les écritures sont plus coûteuses et bien plus rares en usage normal :
        // un plafond distinct évite qu'une boucle d'écriture consomme tout le
        // budget de lecture de l'utilisateur.
        RateLimiter::for('writes', fn (Request $request) => $this->limite($request, 40));

        // L'import et les exports mobilisent la base pendant plusieurs
        // secondes. Quelques appels par minute suffisent largement à l'usage
        // réel, et au-delà c'est un script, pas un humain.
        RateLimiter::for('heavy', fn (Request $request) => $this->limite($request, 6));
    }

    private function limite(Request $request, int $parMinute): Limit
    {
        $user = $request->user();

        $limite = $user instanceof User
            ? Limit::perMinute($parMinute)->by('u'.$user->id)
            // Requête non authentifiée : on retombe sur l'IP, faute de mieux,
            // avec un plafond plus bas — il n'y a aucune raison légitime de
            // marteler l'API sans jeton.
            : Limit::perMinute(20)->by($request->ip());

        return $limite->response(fn () => response()->json([
            'code' => 'rate_limit.exceeded',
            'message' => 'Trop de requêtes. Patientez quelques instants.',
        ], Response::HTTP_TOO_MANY_REQUESTS));
    }
}
