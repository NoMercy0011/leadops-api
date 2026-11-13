<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectActivityResource;
use App\Models\Project;
use App\Models\ProjectActivity;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Journal de configuration d'un projet.
 *
 * En lecture seule, comme le journal d'un prospect : un historique qui se
 * modifie ne prouve plus rien.
 */
class ProjectActivityController extends Controller
{
    public function __invoke(Project $project): AnonymousResourceCollection
    {
        // Lire qui a changé quoi relève de l'encadrement, comme la santé du
        // pipeline et les rapports.
        $this->authorize('viewReports', $project);

        return ProjectActivityResource::collection(
            ProjectActivity::query()
                ->where('project_id', $project->id)
                ->with('user')
                // Antichronologique : la dernière décision prise est celle
                // qu'on vient vérifier.
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                // Paginé par 50, comme le journal d'un prospect (lot 9, contrat
                // §5) : figé à 50 lignes, l'historique au-delà devenait
                // inaccessible.
                ->paginate(50)
        );
    }
}
