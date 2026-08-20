<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\JsonResponse;

class CompanyUsageController extends Controller
{
    public function __construct(private readonly SubscriptionLimitService $limits) {}

    /**
     * Consommation d'une entreprise face aux plafonds de son plan.
     *
     * `limit` et `remaining` valent `null` pour un plan illimité : le front
     * affiche alors « illimité » plutôt qu'une jauge, qui n'aurait pas de sens.
     */
    public function __invoke(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return response()->json([
            'data' => [
                'company_id' => $company->id,
                'usage' => $this->limits->usage($company),
            ],
        ]);
    }
}
