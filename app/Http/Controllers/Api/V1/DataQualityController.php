<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DataQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Défauts de données d'un projet.
 *
 * Séparé de la santé du pipeline bien que les deux vivent côte à côte à
 * l'écran : les requêtes n'ont rien de commun, et les réunir ferait payer le
 * parcours des doublons à qui ne regarde que les durées.
 */
class DataQualityController extends Controller
{
    public function __construct(
        private readonly DataQualityService $quality,
    ) {}

    public function __invoke(Request $request, Project $project): JsonResponse
    {
        // Même règle que la santé du pipeline et les rapports : un audit porte
        // sur l'activité d'une équipe, il relève de l'encadrement.
        $this->authorize('viewReports', $project);

        return response()->json([
            'data' => $this->quality->forProject($project, $request->user()),
        ]);
    }
}
