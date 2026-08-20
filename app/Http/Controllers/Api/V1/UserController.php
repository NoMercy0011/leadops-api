<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(private readonly SubscriptionLimitService $limits) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $request->user();

        // Deux couches successives : CompanyScope a déjà borné la requête à
        // l'entreprise, `visibleTo` applique ensuite la restriction de rôle.
        return UserResource::collection(
            User::query()->visibleTo($viewer)->orderBy('name')->paginate(25)
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $company = $request->user()->company;

        // Contrôle du plafond avant création — invariant n°4. Le front peut
        // griser le bouton, mais c'est cette ligne qui fait autorité.
        $this->limits->assertCanCreate($company, 'users');

        // `company_id` n'est volontairement pas passé : l'observer du trait
        // BelongsToCompany la renseigne depuis l'utilisateur authentifié.
        $user = User::query()->create($request->safe()->only([
            'name', 'email', 'password', 'role', 'manager_id',
        ]));

        return (new UserResource($user))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
