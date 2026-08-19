<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Profil de l'utilisateur authentifié, avec son entreprise et son
     * abonnement. Le front s'en sert pour composer la navigation et connaître
     * l'état d'écriture de l'entreprise.
     */
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user();

        return new UserResource(
            $user->load('company.subscription.plan')
        );
    }
}
