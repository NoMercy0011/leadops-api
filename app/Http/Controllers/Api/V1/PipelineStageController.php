<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StageColor;
use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineStageResource;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\Prospect;
use App\Services\PipelineService;
use App\Services\ProjectJournal;
use App\Services\ProspectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PipelineStageController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
        private readonly ProspectService $prospects,
        private readonly ProjectJournal $journal,
    ) {}

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
            'is_qualified' => ['sometimes', 'boolean'],
        ]);

        $this->assertTerminalitéCohérente($project, $donnees);

        // La modification et sa trace au journal réussissent ou échouent
        // ensemble (lot 9, règle R7) : une étape ajoutée sans trace ferait
        // mentir le journal du projet.
        $stage = DB::transaction(function () use ($donnees, $project) {
            $stage = PipelineStage::query()->create([
                ...$donnees,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'position' => $this->pipeline->nextPosition($project),
            ]);

            $this->journal->stageAdded($stage);

            return $stage;
        });

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
            'is_qualified' => ['sometimes', 'boolean'],
        ]);

        $this->assertTerminalitéCohérente($project, $donnees, $stage);

        // Le libellé est relevé avant la modification : c'est le renommage qui
        // change rétroactivement le sens des données déjà saisies, et le
        // journal doit pouvoir dire de quoi vers quoi.
        $ancienNom = $stage->name;

        DB::transaction(function () use ($stage, $donnees, $ancienNom): void {
            $stage->update($donnees);

            $this->journal->stageUpdated($stage->fresh() ?? $stage, $ancienNom);
        });

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

        DB::transaction(function () use ($project, $donnees): void {
            $this->pipeline->reorder($project, $donnees['stages']);

            $this->journal->stagesReordered($project->fresh() ?? $project);
        });

        return PipelineStageResource::collection($project->stages()->get());
    }

    public function destroy(Request $request, PipelineStage $stage): JsonResponse
    {
        $this->authorize('managePipeline', $stage->project);

        $restantes = $stage->project->stages()->count();

        if ($restantes <= 1) {
            throw ValidationException::withMessages([
                'stage' => 'Un projet doit conserver au moins une étape : sans elle, aucun prospect ne pourrait y être créé.',
            ]);
        }

        $occupants = Prospect::query()->where('stage_id', $stage->id)->count();

        if ($occupants > 0) {
            // Une étape de repli est exigée plutôt qu'un déplacement d'office
            // vers la première étape : ramener des prospects qualifiés au début
            // du pipeline effacerait le travail déjà fait sur eux. Le choix
            // revient à l'utilisateur.
            $repli = $request->integer('fallback_stage_id');

            if ($repli === 0) {
                throw ValidationException::withMessages([
                    'fallback_stage_id' => "Cette étape contient {$occupants} prospect".($occupants > 1 ? 's' : '').'. Indiquez vers quelle étape les déplacer.',
                ]);
            }

            $destination = $stage->project->stages()->whereKey($repli)->first();

            if ($destination === null || $destination->id === $stage->id) {
                throw ValidationException::withMessages([
                    'fallback_stage_id' => "L'étape de repli doit être une autre étape du même projet.",
                ]);
            }

            DB::transaction(function () use ($stage, $destination) {
                // Chaque déplacement est journalisé : sans cela, l'historique
                // d'un prospect montrerait un saut inexpliqué, et les durées de
                // cycle du lot 7 seraient calculées sur des étapes fantômes.
                Prospect::query()
                    ->where('stage_id', $stage->id)
                    ->with('stage')
                    ->chunkById(200, function ($prospects) use ($destination) {
                        foreach ($prospects as $prospect) {
                            $this->prospects->moveToStage($prospect, $destination);
                        }
                    });

                $stage->delete();

                $this->journal->stageRemoved($stage->project, $stage->name, $destination->name);
            });

            return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
        }

        $projet = $stage->project;
        $nom = $stage->name;

        DB::transaction(function () use ($stage, $projet, $nom): void {
            $stage->delete();

            $this->journal->stageRemoved($projet, $nom, null);
        });

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
        $drapeaux = [
            'is_won' => 'gagnante',
            'is_lost' => 'perdue',
            'is_qualified' => 'qualifiante',
        ];

        foreach ($drapeaux as $drapeau => $libelle) {
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
