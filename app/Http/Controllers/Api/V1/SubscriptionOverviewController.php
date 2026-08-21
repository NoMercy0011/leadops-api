<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abonnement et consommation de sa propre entreprise.
 *
 * La même lecture existait déjà, mais réservée au Super Admin sous `/admin`.
 * L'invariant n°4 veut que les plafonds soient tenus par le serveur ; encore
 * faut-il que le client puisse les voir approcher, sans quoi le premier signe
 * du plafond est un refus de création en pleine saisie.
 *
 * Volontairement hors du groupe `subscription.writes` : c'est précisément
 * quand l'abonnement est suspendu ou expiré que cet écran doit répondre, sinon
 * l'Admin Client se retrouve bloqué sans pouvoir consulter la raison.
 */
class SubscriptionOverviewController extends Controller
{
    public function __construct(private readonly SubscriptionLimitService $limits) {}

    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->user()?->company;

        // Le Super Admin n'a pas d'entreprise : il dispose de sa propre route,
        // qui prend l'entreprise en paramètre. Le renvoyer ici n'aurait pas de
        // sens, et lui répondre « aucune entreprise » encore moins.
        abort_if($company === null, Response::HTTP_FORBIDDEN);

        $this->authorize('viewSubscription', $company);

        $company->loadMissing('subscription.plan');

        return response()->json([
            'data' => [
                // Une entreprise sans abonnement est un état anormal, pas une
                // erreur : le service compte alors un plafond à zéro, et
                // l'écran doit pouvoir le dire plutôt que planter.
                'subscription' => $company->subscription !== null
                    ? new SubscriptionResource($company->subscription)
                    : null,
                'usage' => $this->limits->usage($company),
            ],
        ]);
    }
}
