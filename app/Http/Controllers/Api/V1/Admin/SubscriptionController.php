<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\Plan;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionLimitService $limits) {}

    /**
     * Changement de plan, de statut ou d'échéance.
     */
    public function update(Request $request, Company $company): SubscriptionResource
    {
        $this->authorize('manageSubscription', $company);

        $donnees = $request->validate([
            'plan_id' => ['sometimes', 'integer', Rule::exists('plans', 'id')],
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $subscription = $company->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan_id' => "Cette entreprise n'a pas d'abonnement à modifier.",
            ]);
        }

        if (isset($donnees['plan_id'])) {
            $this->assertNouveauPlanCouvreLaConsommation($company, (int) $donnees['plan_id']);
        }

        $subscription->update($donnees);

        return new SubscriptionResource($subscription->load('plan'));
    }

    /**
     * Une rétrogradation ne doit pas laisser l'entreprise au-dessus de ses
     * nouveaux plafonds.
     *
     * Le laisser passer produirait un état incohérent — des ressources
     * existantes hors quota — que le contrôle à la création ne rattraperait
     * jamais, puisqu'il ne s'applique qu'aux nouvelles.
     */
    private function assertNouveauPlanCouvreLaConsommation(Company $company, int $planId): void
    {
        $nouveauPlan = Plan::query()->findOrFail($planId);

        foreach (SubscriptionLimitService::resources() as $resource) {
            $plafond = $nouveauPlan->limitFor($resource);

            if ($plafond === null) {
                continue;
            }

            $consommation = $this->limits->count($company, $resource);

            if ($consommation > $plafond) {
                throw ValidationException::withMessages([
                    'plan_id' => sprintf(
                        'Cette entreprise compte %d %s, au-delà des %d du plan « %s ». Réduisez-les avant de rétrograder.',
                        $consommation,
                        $resource,
                        $plafond,
                        $nouveauPlan->name,
                    ),
                ]);
            }
        }
    }
}
