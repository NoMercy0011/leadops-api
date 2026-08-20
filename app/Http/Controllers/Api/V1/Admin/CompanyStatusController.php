<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;

class CompanyStatusController extends Controller
{
    public function suspend(Company $company): CompanyResource
    {
        $this->authorize('changeStatus', $company);

        $company->update(['status' => CompanyStatus::Suspended]);

        // Les jetons existants sont révoqués : sans cela, un utilisateur déjà
        // connecté continuerait de travailler jusqu'à expiration de sa session,
        // ce qui viderait la suspension de son effet immédiat.
        $company->users()->each(fn ($user) => $user->tokens()->delete());

        return new CompanyResource($company->load('subscription.plan'));
    }

    public function activate(Company $company): CompanyResource
    {
        $this->authorize('changeStatus', $company);

        $company->update(['status' => CompanyStatus::Active]);

        return new CompanyResource($company->load('subscription.plan'));
    }
}
