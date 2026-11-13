<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Prospect;
use App\Services\ProspectImporter;
use App\Support\ProspectListFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProspectImportController extends Controller
{
    public function __construct(private readonly ProspectImporter $importer) {}

    /**
     * Import CSV.
     *
     * Répond 200 même en présence de lignes rejetées : l'import a bien eu
     * lieu, partiellement. Un 422 laisserait croire que rien n'a été enregistré
     * alors que les lignes valides le sont.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('import', Prospect::class);

        $donnees = $request->validate([
            'project_id' => [
                'required', 'integer',
                Rule::exists('projects', 'id')->where('company_id', $request->user()->company_id),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $project = Project::query()->findOrFail($donnees['project_id']);

        $resultat = $this->importer->import($project, $request->file('file'));

        return response()->json(['data' => $resultat]);
    }

    /**
     * Export CSV du périmètre visible.
     *
     * Réponse en flux : construire la chaîne complète en mémoire ferait
     * dépasser la limite PHP sur un plan Enterprise, dont la volumétrie est
     * illimitée par définition.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', Prospect::class);

        // Le même filtre que la liste (lot 9, faille F17) : vue des archivés,
        // projet, étape, commercial et recherche. L'export renvoie ce que
        // l'écran montre, ni plus ni moins.
        $query = ProspectListFilter::query($request)
            ->with(['stage', 'assignedUser', 'project'])
            ->orderBy('id');

        $nomFichier = 'prospects-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $sortie = fopen('php://output', 'w');

            // BOM UTF-8 : sans lui, Excel sous Windows affiche « Ã© » à la
            // place des accents, ce qui rend l'export inutilisable en France
            // comme à Madagascar.
            fwrite($sortie, "\xEF\xBB\xBF");

            fputcsv($sortie, [
                'Prénom', 'Nom', 'Société', 'Téléphone', 'Email',
                'Adresse', 'Source', 'Projet', 'Étape', 'Commercial',
                'Prochaine action', 'Converti le', 'Perdu le', 'Créé le',
            ], separator: ';', escape: '');

            $query->chunk(500, function ($prospects) use ($sortie) {
                foreach ($prospects as $prospect) {
                    fputcsv($sortie, [
                        $prospect->first_name,
                        $prospect->last_name,
                        $prospect->company_name,
                        $prospect->phone,
                        $prospect->email,
                        $prospect->address,
                        $prospect->source,
                        $prospect->project->name,
                        $prospect->stage->name,
                        // Seul le commercial peut manquer : un prospect non
                        // affecté est un état normal.
                        $prospect->assignedUser?->name,
                        $prospect->next_action_at?->toDateString(),
                        $prospect->converted_at?->toDateString(),
                        $prospect->lost_at?->toDateString(),
                        $prospect->created_at?->toDateString(),
                    ], separator: ';', escape: '');
                }
            });

            fclose($sortie);
        }, $nomFichier, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
