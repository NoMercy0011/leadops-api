<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Exceptions\ArchivedReadOnly;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\PipelineService;
use App\Services\ProjectJournal;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private readonly SubscriptionLimitService $limits,
        private readonly PipelineService $pipeline,
        private readonly ProjectJournal $journal,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            // Deux couches successives : CompanyScope a borné la requête à
            // l'entreprise, `visibleTo` applique la restriction de rôle.
            ->visibleTo($request->user())
            ->with(['variant', 'stages'])
            ->withCount('members')
            ->when(
                $request->string('status')->value(),
                // Un statut demandé explicitement fait autorité, archivé
                // compris : c'est ainsi qu'on retrouve ce qu'on a rangé.
                fn ($query, string $statut) => $query->where('status', $statut),
                // Sans filtre, les projets archivés sont écartés. C'est tout
                // l'objet de l'archivage : sortir de la vue courante sans
                // rien détruire. Les afficher malgré tout viderait le geste
                // de son sens.
                fn ($query) => $query->whereIn('status', ProjectStatus::visibleParDefaut()),
            )
            ->when(
                $request->integer('variant_id'),
                fn ($query, int $variantId) => $query->where('variant_id', $variantId)
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $company = $request->user()->company;

        // Invariant n°4 : le plafond est vérifié avant création, côté serveur.
        $this->limits->assertCanCreate($company, 'projects');

        $project = DB::transaction(function () use ($request) {
            $project = Project::query()->create($request->safe()->only([
                'variant_id', 'name', 'product', 'target', 'description', 'status',
            ]));

            // Un projet sans étape serait inutilisable : aucun prospect ne
            // pourrait y entrer. Le pipeline par défaut est donc posé d'office,
            // quitte à être intégralement remanié ensuite.
            $this->pipeline->createDefaultStages($project);

            if ($request->has('member_ids')) {
                $project->members()->sync($request->input('member_ids', []));
            }

            $this->journal->projectCreated($project);

            return $project;
        });

        return (new ProjectResource($project->load(['variant', 'stages', 'members'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource(
            $project->load(['variant', 'stages', 'members'])
        );
    }

    public function update(Request $request, Project $project): ProjectResource
    {
        $this->authorize('update', $project);

        $companyId = $request->user()->company_id;

        $donnees = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('projects', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($project),
            ],
            'variant_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('variants', 'id')->where('company_id', $companyId),
            ],
            'product' => ['sometimes', 'nullable', 'string', 'max:255'],
            'target' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
        ]);

        $ancienStatut = $project->status;
        $nouveauStatut = isset($donnees['status']) ? ProjectStatus::from($donnees['status']) : $ancienStatut;

        // Un projet archivé n'accepte que son changement de statut (lot 9,
        // règle R3) : c'est le geste qui le réactive.
        if (array_diff(array_keys($donnees), ['status']) !== []) {
            ArchivedReadOnly::guard($project);
        }

        // Quitter l'archive reprend une place dans le plafond : elle se
        // contrôle comme une création (lot 9, règle R2).
        if ($ancienStatut->isArchived() && ! $nouveauStatut->isArchived()) {
            $company = $request->user()->company;
            $this->limits->assertCanCreate($company, 'projects');
        }

        // Le changement de statut est journalisé à part : « archivé » et
        // « renommé » n'appellent pas la même lecture, et les mêler dans un
        // seul événement obligerait l'écran à fouiller le payload.
        $avant = $project->only(['name', 'product', 'target', 'description', 'variant_id']);

        // La modification et sa trace au journal réussissent ou échouent
        // ensemble (lot 9, règle R7).
        DB::transaction(function () use ($project, $donnees, $ancienStatut, $avant): void {
            $project->update($donnees);

            if (array_key_exists('status', $donnees) && $project->status !== $ancienStatut) {
                $this->journal->statusChanged(
                    $project,
                    $ancienStatut->label(),
                    $project->status->label(),
                );
            }

            $this->journal->projectUpdated(
                $project,
                $avant,
                $project->only(['name', 'product', 'target', 'description', 'variant_id']),
            );
        });

        return new ProjectResource($project->load(['variant', 'stages', 'members']));
    }

    /*
     * Il n'y a volontairement pas de `destroy`.
     *
     * Les clés étrangères de `prospects`, `pipeline_stages` et `appointments`
     * sont en `cascadeOnDelete` : supprimer un projet emporterait ses
     * prospects, leur journal d'activités et tous leurs rendez-vous d'un seul
     * appel, sans retour possible — et les KPIs de durée de cycle qui en
     * dépendent ne se reconstituent pas.
     *
     * Un projet se retire donc de la circulation par son statut : « Terminé »
     * quand il est mené à son terme, « Archivé » quand on veut simplement le
     * sortir de la vue courante. Les deux conservent tout et restent
     * consultables et exportables — la même asymétrie que pour un abonnement
     * suspendu, où l'on ferme l'écriture sans jamais fermer la lecture.
     */
}
