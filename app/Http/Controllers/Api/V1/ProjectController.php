<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\PipelineService;
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
                fn ($query, string $statut) => $query->where('status', $statut)
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

        $project->update($donnees);

        return new ProjectResource($project->load(['variant', 'stages', 'members']));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
