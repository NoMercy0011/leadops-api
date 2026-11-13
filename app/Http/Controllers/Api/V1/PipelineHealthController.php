<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\PipelineHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Santé du pipeline d'un projet.
 *
 * Séparé de `GET /projects/{project}` plutôt qu'ajouté à sa réponse : le
 * calcul parcourt le journal d'activités, alors que la fiche est chargée à
 * chaque navigation. Les mêler ferait payer ce parcours même à qui ne regarde
 * que l'équipe ou le questionnaire.
 */
class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineHealthService $health,
    ) {}

    public function __invoke(Request $request, Project $project): JsonResponse
    {
        // Même règle que les rapports : lire l'activité d'une équipe relève de
        // l'encadrement. Un commercial dispose de sa liste de prospects, où
        // figure déjà ce qui le concerne.
        $this->authorize('viewReports', $project);

        return response()->json([
            'data' => $this->health->forProject(
                $project->load('stages'),
                $request->user(),
            ),
        ]);
    }
}
