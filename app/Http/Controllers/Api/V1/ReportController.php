<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    /**
     * Tableau de bord — §2 de l'esquisse.
     *
     * Servi depuis un cache court, dont la clé porte l'identifiant de
     * l'utilisateur : le contenu dépend de son rôle, et une clé par entreprise
     * servirait la vue d'un manager à un commercial.
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Le Super Admin n'a pas d'activité commerciale à consulter : son
        // tableau de bord est celui de la plateforme, au back-office.
        $this->authorize('viewAny', Prospect::class);

        return response()->json([
            'data' => $this->reporting->cachedDashboard($request->user()),
        ]);
    }

    /**
     * Rapports filtrés — §11 de l'esquisse.
     *
     * Non mis en cache, contrairement au tableau de bord : les combinaisons de
     * filtres sont trop nombreuses pour qu'un cache atteigne un taux utile, et
     * chacune ne sert qu'une fois.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Prospect::class);

        $filtres = $this->valider($request);

        return response()->json([
            'data' => $this->reporting->report($request->user(), $filtres),
        ]);
    }

    /**
     * Export CSV du rapport, en flux.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Prospect::class);

        $rapport = $this->reporting->report($request->user(), $this->valider($request));

        return response()->streamDownload(function () use ($rapport) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel sous Windows massacre les accents et
            // l'export devient inutilisable.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, ['Rapport LeadOps'], separator: ';', escape: '');
            fputcsv($sortie, ['Période', $rapport['period']['from'], $rapport['period']['to']], separator: ';', escape: '');
            fputcsv($sortie, [], separator: ';', escape: '');

            fputcsv($sortie, ['Indicateur', 'Valeur'], separator: ';', escape: '');
            foreach ([
                'Prospects' => $rapport['totals']['prospects'],
                'Contactés' => $rapport['totals']['contacted'],
                'Qualifiés' => $rapport['totals']['qualified'],
                'Convertis' => $rapport['totals']['converted'],
                'Perdus' => $rapport['totals']['lost'],
                'Rendez-vous' => $rapport['totals']['appointments'],
                'Taux de qualification (%)' => $rapport['rates']['qualification'] ?? '—',
                'Taux de conversion (%)' => $rapport['rates']['conversion'] ?? '—',
            ] as $libelle => $valeur) {
                fputcsv($sortie, [$libelle, $valeur], separator: ';', escape: '');
            }

            foreach ([
                'Par projet' => ['by_project', 'project', 'variant'],
                'Par commercial' => ['by_user', 'user', null],
            ] as $titre => [$cle, $colonne, $secondaire]) {
                fputcsv($sortie, [], separator: ';', escape: '');
                fputcsv($sortie, [$titre], separator: ';', escape: '');
                fputcsv($sortie, array_filter([
                    ucfirst($colonne), $secondaire ? 'Variante' : null,
                    'Prospects', 'Qualifiés', 'Convertis', 'Perdus',
                    'Taux qualification (%)', 'Taux conversion (%)',
                ]), separator: ';', escape: '');

                foreach ($rapport[$cle] as $ligne) {
                    fputcsv($sortie, array_filter([
                        $ligne[$colonne],
                        $secondaire ? ($ligne[$secondaire] ?? '—') : null,
                        $ligne['total'],
                        $ligne['qualified'],
                        $ligne['converted'],
                        $ligne['lost'],
                        $ligne['qualification_rate'] ?? '—',
                        $ligne['conversion_rate'] ?? '—',
                    ], fn ($v) => $v !== null), separator: ';', escape: '');
                }
            }

            fclose($sortie);
        }, 'rapport-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Filtres du §11 : projet, variante, commercial, période.
     *
     * L'axe « entreprise » du §11 n'apparaît pas : il est appliqué en amont par
     * le cloisonnement tenant, et l'exposer ici en ferait un filtre
     * contournable.
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'project_id' => [
                'sometimes', 'integer',
                Rule::exists('projects', 'id')->where('company_id', $companyId),
            ],
            'variant_id' => [
                'sometimes', 'integer',
                Rule::exists('variants', 'id')->where('company_id', $companyId),
            ],
            'user_id' => [
                'sometimes', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
    }
}
