<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ce projet face aux autres de sa variante.
 *
 * La variante est l'axe de comparaison entre offres — c'est ce qui a justifié
 * d'en faire une table de référence plutôt qu'un champ libre (décision §10.4).
 * Les rapports savaient déjà filtrer dessus, mais depuis un écran global : il
 * fallait quitter le projet, ouvrir les rapports, sélectionner la variante et
 * retrouver sa ligne. La question posée sur une fiche projet est plus directe —
 * « est-ce que cette offre marche mieux que l'autre ? ».
 *
 * Aucune requête n'est écrite ici : le calcul passe par `ReportingService`,
 * seul à connaître les jalons et le cloisonnement. En refaire une variante
 * locale créerait un second taux de conversion, qui divergerait du premier au
 * premier changement de règle.
 */
class VariantComparisonController extends Controller
{
    public function __construct(
        private readonly ReportingService $reporting,
    ) {}

    public function __invoke(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewReports', $project);

        $project->loadMissing('variant');

        // Sans variante, il n'y a pas d'axe de comparaison. Renvoyer une liste
        // vide serait ambigu — l'écran ne saurait pas distinguer « aucune
        // variante » de « seule offre de sa variante ».
        if ($project->variant === null) {
            return response()->json([
                'data' => ['variant' => null, 'projets' => []],
            ]);
        }

        // La période est ouverte explicitement. `report()` retient les trente
        // derniers jours par défaut, ce qui n'a pas de sens ici : comparer deux
        // offres sur un mois favoriserait mécaniquement la plus récente, dont
        // les dossiers n'ont pas eu le temps de se conclure — et un projet
        // lancé il y a six mois ressortirait à 0 %.
        $rapport = $this->reporting->report($request->user(), [
            'variant_id' => $project->variant->id,
            'from' => '2000-01-01',
            'to' => now()->toDateString(),
        ]);

        $projets = array_map(
            fn (array $ligne) => [
                ...$ligne,
                // L'écran doit pouvoir situer le projet courant dans la liste
                // sans comparer des libellés, qui peuvent se ressembler.
                'courant' => ($ligne['project_id'] ?? null) === $project->id,
            ],
            $rapport['by_project'],
        );

        return response()->json([
            'data' => [
                'variant' => $project->variant->name,
                'projets' => $projets,
            ],
        ]);
    }
}
