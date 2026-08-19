<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Referme le point faible de CompanyScope.
 *
 * Le scope ne filtre rien lorsqu'aucun utilisateur n'est authentifié — un choix
 * assumé pour les seeders et les commandes console. La conséquence est qu'une
 * route métier oubliée hors de `auth:sanctum` exposerait les données de toutes
 * les entreprises. Ce test rend cet oubli impossible : ajouter une route
 * publique fait échouer la suite, et il faut alors l'inscrire explicitement
 * dans la liste ci-dessous.
 */
class RouteProtectionTest extends TestCase
{
    /**
     * Routes accessibles sans jeton, chacune justifiée.
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        'auth.login',             // sans jeton par définition
        'auth.forgot-password',   // l'utilisateur ne peut pas se connecter
        'auth.reset-password',    // porte son propre jeton à usage unique
    ];

    public function test_aucune_route_api_n_est_publique_sans_justification(): void
    {
        $publiques = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn (RoutingRoute $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
            ->values();

        $nonJustifiees = $publiques->reject(
            fn (string $nom) => in_array($nom, self::PUBLIC_ROUTES, true)
        );

        $this->assertTrue(
            $nonJustifiees->isEmpty(),
            'Ces routes API sont accessibles sans authentification et ne figurent pas '
            ."dans la liste justifiée : \n- ".$nonJustifiees->implode("\n- ")
            ."\n\nUne route métier publique contourne le cloisonnement par entreprise. "
            .'Ajoutez `auth:sanctum`, ou inscrivez-la dans PUBLIC_ROUTES avec sa raison.'
        );
    }

    public function test_les_routes_declarees_publiques_existent_encore(): void
    {
        // Empêche la liste de dérive : une entrée obsolète qu'on aurait oublié
        // de retirer masquerait la disparition d'une protection.
        foreach (self::PUBLIC_ROUTES as $nom) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($nom),
                "La route publique déclarée « {$nom} » n'existe plus : retirez-la de PUBLIC_ROUTES."
            );
        }
    }
}
