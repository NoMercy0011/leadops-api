<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $companies = Company::query()
            ->with('subscription.plan')
            ->when(
                $request->string('search')->trim()->value(),
                fn ($query, string $terme) => $query->where(
                    fn ($q) => $q->where('name', 'like', "%{$terme}%")->orWhere('slug', 'like', "%{$terme}%")
                )
            )
            ->when(
                $request->string('status')->value(),
                fn ($query, string $statut) => $query->where('status', $statut)
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        // L'entreprise et son abonnement forment un tout : créer l'une sans
        // l'autre laisserait un tenant incapable de rien créer.
        $company = DB::transaction(function () use ($request) {
            // `status` est renseigné explicitement, alors que la colonne porte
            // déjà cette valeur par défaut en base. Une valeur par défaut ne
            // s'applique qu'à l'INSERT : l'instance en mémoire conservait un
            // `status` nul, et CompanyResource, qui lit `status->value`, faisait
            // échouer la réponse 201 sur un accès à une propriété de null. Le
            // `load()` qui suit ne recharge que les relations, pas les
            // attributs, et ne rattrapait donc rien.
            $company = Company::query()->create([
                ...$request->safe()->only([
                    'name', 'slug', 'timezone', 'default_country_code',
                ]),
                'status' => CompanyStatus::Active,
            ]);

            Subscription::query()->create([
                'company_id' => $company->id,
                'plan_id' => $request->integer('plan_id'),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'expires_at' => $request->date('expires_at'),
            ]);

            return $company;
        });

        return (new CompanyResource($company->load('subscription.plan')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return new CompanyResource($company->load('subscription.plan'));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());

        return new CompanyResource($company->load('subscription.plan'));
    }
}
