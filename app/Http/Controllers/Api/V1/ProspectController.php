<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProspectRequest;
use App\Http\Resources\ProspectResource;
use App\Models\Project;
use App\Models\Prospect;
use App\Services\ProspectService;
use App\Services\SubscriptionLimitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProspectController extends Controller
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly SubscriptionLimitService $limits,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Prospect::class);

        $prospects = $this->filtree($request)
            ->with(['stage', 'assignedUser'])
            // Dernière interaction dérivée du journal plutôt que stockée.
            ->withMax('activities', 'occurred_at')
            ->orderByDesc('updated_at')
            ->paginate((int) $request->integer('per_page', 25))
            ->withQueryString();

        // Le résumé porte sur le filtre entier, pas sur la page affichée :
        // « 12 relances en retard » n'a de sens que rapporté à l'ensemble.
        // Le calculer côté front sur `data` donnerait un compte faux dès la
        // seconde page.
        return ProspectResource::collection($prospects)
            ->additional(['summary' => $this->resume($request)]);
    }

    /**
     * Requête de base : cloisonnement, périmètre de rôle et filtres de l'URL.
     *
     * Partagée par la liste et par son résumé, pour que les deux portent
     * exactement sur le même ensemble. Deux constructions séparées finiraient
     * par diverger, et le résumé mentirait sans que rien ne le signale.
     *
     * @return Builder<Prospect>
     */
    private function filtree(Request $request): Builder
    {
        return Prospect::query()
            // Deux couches : CompanyScope borne à l'entreprise, `visibleTo`
            // applique ensuite la restriction de rôle.
            ->visibleTo($request->user())
            ->when(
                $request->integer('project_id'),
                fn ($q, int $id) => $q->where('project_id', $id)
            )
            ->when(
                $request->integer('stage_id'),
                fn ($q, int $id) => $q->where('stage_id', $id)
            )
            ->when(
                $request->has('assigned_user_id'),
                function ($q) use ($request) {
                    $valeur = $request->input('assigned_user_id');

                    // « none » filtre les non affectés, ce qu'un identifiant
                    // numérique ne peut pas exprimer.
                    return $valeur === 'none'
                        ? $q->whereNull('assigned_user_id')
                        : $q->where('assigned_user_id', (int) $valeur);
                }
            )
            ->when(
                $request->string('search')->trim()->value(),
                fn ($q, string $terme) => $q->where(function ($sub) use ($terme) {
                    $sub->where('first_name', 'like', "%{$terme}%")
                        ->orWhere('last_name', 'like', "%{$terme}%")
                        ->orWhere('company_name', 'like', "%{$terme}%")
                        ->orWhere('phone', 'like', "%{$terme}%")
                        ->orWhere('email', 'like', "%{$terme}%");
                })
            );
    }

    /**
     * Compteurs d'en-tête de liste.
     *
     * Une seule requête à agrégats conditionnels plutôt que cinq `count()` :
     * la table des prospects est la plus volumineuse du produit (plafond
     * Enterprise illimité), et cet écran est le plus consulté.
     *
     * « En retard » exclut les prospects convertis ou perdus : une relance
     * planifiée sur un dossier clos n'appelle plus aucune action, et la
     * compter gonflerait indéfiniment un indicateur censé appeler à agir.
     *
     * @return array<string, int>
     */
    private function resume(Request $request): array
    {
        $ligne = $this->filtree($request)
            ->selectRaw(<<<'SQL'
                count(*) as total,
                sum(case when assigned_user_id is null then 1 else 0 end) as non_affectes,
                sum(case when converted_at is not null then 1 else 0 end) as convertis,
                sum(case when lost_at is not null then 1 else 0 end) as perdus,
                sum(case
                    when next_action_at is not null
                     and next_action_at < ?
                     and converted_at is null
                     and lost_at is null
                    then 1 else 0 end) as en_retard
            SQL, [now()])
            ->first();

        return [
            'total' => (int) ($ligne->total ?? 0),
            'non_affectes' => (int) ($ligne->non_affectes ?? 0),
            'convertis' => (int) ($ligne->convertis ?? 0),
            'perdus' => (int) ($ligne->perdus ?? 0),
            'en_retard' => (int) ($ligne->en_retard ?? 0),
        ];
    }

    public function store(StoreProspectRequest $request): JsonResponse
    {
        $project = Project::query()->findOrFail($request->integer('project_id'));

        $this->limits->assertCanCreate($project->company, 'prospects');

        $prospect = $this->prospects->create($project, $request->safe()->except('project_id'));

        return (new ProspectResource($prospect->load(['stage', 'assignedUser'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Prospect $prospect): ProspectResource
    {
        $this->authorize('view', $prospect);

        return new ProspectResource(
            $prospect->load(['stage', 'project', 'assignedUser'])
        );
    }

    public function update(Request $request, Prospect $prospect): ProspectResource
    {
        $this->authorize('update', $prospect);

        $donnees = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_action_at' => ['sometimes', 'nullable', 'date'],
            'next_action_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $prospect->fill($donnees);

        // Recalculé à chaque écriture : corriger un numéro sans mettre à jour
        // les colonnes de rapprochement laisserait la détection de doublons
        // travailler sur l'ancienne valeur.
        $this->prospects->applyNormalizedContact($prospect, $prospect->project);

        $prospect->save();

        return new ProspectResource($prospect->load(['stage', 'assignedUser']));
    }

    /**
     * Doublons probables dans un projet, avant création.
     *
     * Point d'entrée dédié plutôt que contrôle bloquant : l'esquisse admet
     * qu'un même contact existe dans plusieurs projets, et la décision §10.1
     * retient le signalement, jamais le refus.
     */
    public function duplicates(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Prospect::class);

        $donnees = $request->validate([
            'project_id' => [
                'required', 'integer',
                Rule::exists('projects', 'id')->where('company_id', $request->user()->company_id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        $project = Project::query()->findOrFail($donnees['project_id']);

        return ProspectResource::collection(
            $this->prospects->findDuplicates(
                $project,
                $donnees['phone'] ?? null,
                $donnees['email'] ?? null,
            )->load('assignedUser')
        );
    }

    public function destroy(Prospect $prospect): JsonResponse
    {
        $this->authorize('delete', $prospect);

        $prospect->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
