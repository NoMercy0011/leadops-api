<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StageColor;
use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineStageResource;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PipelineStageController extends Controller
{
    public function __construct(private readonly PipelineService $pipeline) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return PipelineStageResource::collection($project->stages);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('managePipeline', $project);

        $donnees = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('pipeline_stages', 'name')->where('project_id', $project->id),
            ],
            'color' => ['sometimes', Rule::enum(StageColor::class)],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ]);

        $this->assertTerminalitéCohérente($project, $donnees);

        $stage = PipelineStage::query()->create([
            ...$donnees,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'position' => $this->pipeline->nextPosition($project),
        ]);

        return (new PipelineStageResource($stage))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(Request $request, PipelineStage $stage): PipelineStageResource
    {
        $project = $stage->project;
        $this->authorize('managePipeline', $project);

        $donnees = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('pipeline_stages', 'name')
                    ->where('project_id', $stage->project_id)
                    ->ignore($stage),
            ],
            'color' => ['sometimes', Rule::enum(StageColor::class)],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ]);

        $this->assertTerminalitéCohérente($project, $donnees, $stage);

        $stage->update($donnees);

        return new PipelineStageResource($stage->fresh());
    }

    /**
     * Réordonne le pipeline.
     */
    public function reorder(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('managePipeline', $project);

        $donnees = $request->validate([
            'stages' => ['required', 'array', 'min:1'],
            'stages.*' => ['integer'],
        ]);

        $this->pipeline->reorder($project, $donnees['stages']);

        return PipelineStageResource::collection($project->stages()->get());
    }

    public function destroy(PipelineStage $stage): JsonResponse
    {
        $this->authorize('managePipeline', $stage->project);

        // Le lot 4 ajoutera le refus si des prospects occupent l'étape, avec
        // obligation de désigner une étape de repli. Rien à vérifier tant que
        // les prospects n'existent pas.

        $restantes = $stage->project->stages()->count();

        if ($restantes <= 1) {
            throw ValidationException::withMessages([
                'stage' => 'Un projet doit conserver au moins une étape : sans elle, aucun prospect ne pourrait y être créé.',
            ]);
        }

        $stage->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Au plus une étape gagnante et une perdante par projet, et jamais les deux
     * drapeaux sur la même étape.
     *
     * La base porte déjà la contrainte via des colonnes générées, ce qui ferme
     * le cas d'une écriture concurrente. Ce contrôle applicatif existe pour
     * renvoyer un message utilisable plutôt qu'une violation d'index brute.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function assertTerminalitéCohérente(
        Project $project,
        array $donnees,
        ?PipelineStage $enCours = null,
    ): void {
        foreach (['is_won' => 'gagnante', 'is_lost' => 'perdue'] as $drapeau => $libelle) {
            if (! ($donnees[$drapeau] ?? false)) {
                continue;
            }

            $existante = $project->stages()
                ->where($drapeau, true)
                ->when($enCours, fn ($q) => $q->whereKeyNot($enCours->id))
                ->first();

            if ($existante !== null) {
                throw ValidationException::withMessages([
                    $drapeau => "L'étape « {$existante->name} » est déjà l'étape {$libelle} de ce projet.",
                ]);
            }
        }

        if (($donnees['is_won'] ?? false) && ($donnees['is_lost'] ?? false)) {
            throw ValidationException::withMessages([
                'is_won' => 'Une étape ne peut pas être à la fois gagnante et perdue.',
            ]);
        }
    }
}
