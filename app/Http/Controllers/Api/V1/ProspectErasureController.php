<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProspectResource;
use App\Models\Prospect;
use App\Services\ProspectErasureService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /prospects/{prospect}/erase` — lot 9, règle R4.
 *
 * Hors `subscription.writes` : une demande d'effacement se traite même
 * abonnement suspendu. Le cloisonnement par entreprise s'applique toujours,
 * par la résolution de route : une fiche d'une autre entreprise répond 404.
 */
class ProspectErasureController extends Controller
{
    public function __construct(private readonly ProspectErasureService $erasure) {}

    public function __invoke(Prospect $prospect): JsonResponse
    {
        $this->authorize('erase', $prospect);

        $autresFiches = $this->erasure->erase($prospect);

        return response()->json([
            'data' => (new ProspectResource($prospect->refresh()))->resolve(),
            'autres_fiches' => $autresFiches->map(fn (Prospect $fiche) => [
                'id' => $fiche->id,
                'project_id' => $fiche->project_id,
                'project' => $fiche->project->name,
            ])->values(),
        ]);
    }
}
