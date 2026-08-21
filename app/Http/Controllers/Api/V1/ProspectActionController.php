<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProspectActivityResource;
use App\Http\Resources\ProspectResource;
use App\Models\PipelineStage;
use App\Models\Prospect;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ProspectService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Actions du §5 de l'esquisse : changer d'étape, affecter, annoter, planifier
 * une relance, et lire l'historique.
 */
class ProspectActionController extends Controller
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly ActivityLogger $journal,
    ) {}

    public function changeStage(Request $request, Prospect $prospect): ProspectResource
    {
        $this->authorize('update', $prospect);

        $donnees = $request->validate([
            'stage_id' => ['required', 'integer'],
        ]);

        // Le modèle est cloisonné : une étape d'une autre entreprise est
        // introuvable. L'appartenance au bon *projet* est vérifiée par le
        // service, deux projets d'une même entreprise n'étant pas distingués
        // par le cloisonnement.
        $stage = PipelineStage::query()->findOrFail($donnees['stage_id']);

        return new ProspectResource(
            $this->prospects->moveToStage($prospect, $stage)
        );
    }

    /**
     * Range un prospect hors du pipeline actif, ou l'y ramène.
     *
     * Distinct de « Perdu », qui est un résultat commercial : un contact
     * injoignable depuis six mois ou une ligne d'import inexploitable n'ont
     * pas été perdus, ils n'ont jamais commencé. Les compter comme perdus
     * ferait mentir le taux de perte.
     *
     * Rien n'est supprimé : la fiche reste consultable par son adresse, son
     * journal d'activités intact, et le geste se défait.
     */
    public function archive(Request $request, Prospect $prospect): ProspectResource
    {
        // Même autorisation que l'affectation : sortir un dossier des
        // statistiques est une décision d'encadrement. Un commercial qui
        // pourrait archiver retirerait ses propres dossiers en souffrance du
        // périmètre de son manager.
        $this->authorize('assign', $prospect);

        $donnees = $request->validate([
            'archived' => ['required', 'boolean'],
            'motif' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $archive = (bool) $donnees['archived'];

        if ($prospect->isArchived() !== $archive) {
            $prospect->forceFill(['archived_at' => $archive ? now() : null])->save();

            $this->journal->archived($prospect, $archive, $donnees['motif'] ?? null);

            // Les taux changent : le tableau de bord doit le refléter sans
            // attendre l'expiration du cache.
            ReportingService::forgetCache($prospect->company_id);
        }

        return new ProspectResource($prospect->load(['stage', 'assignedUser']));
    }

    public function assign(Request $request, Prospect $prospect): ProspectResource
    {
        $this->authorize('assign', $prospect);

        $donnees = $request->validate([
            'assigned_user_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $request->user()->company_id),
            ],
        ]);

        $nouveau = $donnees['assigned_user_id'] === null
            ? null
            : User::query()->findOrFail($donnees['assigned_user_id']);

        return new ProspectResource($this->prospects->assign($prospect, $nouveau));
    }

    /**
     * Réaffectation en masse — décision §10.3.
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $this->authorize('bulkAssign', Prospect::class);

        $donnees = $request->validate([
            'prospect_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'prospect_ids.*' => ['integer'],
            'assigned_user_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $request->user()->company_id),
            ],
        ]);

        $nouveau = $donnees['assigned_user_id'] === null
            ? null
            : User::query()->findOrFail($donnees['assigned_user_id']);

        // L'autorisation s'évalue prospect par prospect, jamais sur le lot :
        // `visibleTo` écarte silencieusement ceux qui sortent du périmètre du
        // demandeur, de sorte qu'un manager ne peut pas réaffecter l'équipe
        // d'un autre en glissant des identifiants dans la liste.
        $prospects = Prospect::query()
            ->visibleTo($request->user())
            ->whereKey($donnees['prospect_ids'])
            ->with('assignedUser')
            ->get();

        $reaffectes = $this->prospects->bulkAssign($prospects, $nouveau);

        return response()->json([
            'data' => [
                'requested' => count($donnees['prospect_ids']),
                // L'écart entre les deux se lit : prospects hors périmètre, ou
                // déjà affectés à la bonne personne.
                'reassigned' => $reaffectes,
                'skipped' => count($donnees['prospect_ids']) - $reaffectes,
            ],
        ]);
    }

    public function addNote(Request $request, Prospect $prospect): JsonResponse
    {
        $this->authorize('update', $prospect);

        $donnees = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $this->journal->noteAdded($prospect, $donnees['note']);

        return response()->json(status: JsonResponse::HTTP_CREATED);
    }

    public function planNextAction(Request $request, Prospect $prospect): ProspectResource
    {
        $this->authorize('update', $prospect);

        $donnees = $request->validate([
            'next_action_at' => ['present', 'nullable', 'date'],
            'next_action_note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($prospect, $donnees) {
            $prospect->update($donnees);

            $this->journal->nextActionPlanned(
                $prospect,
                $donnees['next_action_at'] ?? null,
                $donnees['next_action_note'] ?? null,
            );
        });

        return new ProspectResource($prospect->load(['stage', 'assignedUser']));
    }

    public function activities(Prospect $prospect): AnonymousResourceCollection
    {
        $this->authorize('view', $prospect);

        return ProspectActivityResource::collection(
            $prospect->activities()->with('user')->paginate(50)
        );
    }
}
