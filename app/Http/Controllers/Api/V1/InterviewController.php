<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\Interview;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Question;
use App\Services\InterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Entretien guidé — lot 13, contrat `cadrage/contrats/LOT-13_Entretien-guide.md` §2.
 */
class InterviewController extends Controller
{
    public function __construct(private readonly InterviewService $entretiens) {}

    public function plan(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $this->planPour($request, $project)]);
    }

    public function saveTimings(Request $request, Project $project): JsonResponse
    {
        // Lire le projet suffit : une durée personnelle ne touche que soi.
        $this->authorize('view', $project);

        $donnees = $request->validate([
            'timings' => ['required', 'array', 'min:1'],
            'timings.*.question_id' => ['required', 'integer'],
            'timings.*.seconds' => ['required', 'integer', 'min:1', 'max:3600'],
        ]);

        /** @var list<array{question_id: int, seconds: int}> $durees */
        $durees = $donnees['timings'];
        $this->entretiens->saveTimings($project, $request->user(), $durees);

        return response()->json(['data' => $this->planPour($request, $project)]);
    }

    public function resetTimings(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);
        $this->entretiens->resetTimings($project, $request->user());

        return response()->noContent();
    }

    public function start(Request $request, Prospect $prospect): JsonResponse
    {
        // Périmètre du rôle et lecture seule (archivé, effacé) : la policy
        // `update` lève les codes existants.
        $this->authorize('update', $prospect);

        [$entretien, $cree] = $this->entretiens->start($prospect, $request->user());

        return response()->json(['data' => $this->ressource($entretien)], $cree ? 201 : 200);
    }

    public function current(Prospect $prospect): JsonResponse
    {
        $this->authorize('view', $prospect);
        $entretien = $this->entretiens->current($prospect);

        return response()->json(['data' => $entretien === null ? null : $this->ressource($entretien)]);
    }

    public function answer(Request $request, Interview $interview, Question $question): JsonResponse
    {
        $this->assertAuteur($request, $interview);

        return response()->json([
            'data' => $this->ressource($this->entretiens->answer($interview, $question, $request->input('value'))),
        ]);
    }

    public function finish(Request $request, Interview $interview): JsonResponse
    {
        $this->assertAuteur($request, $interview);

        return response()->json(['data' => $this->ressource($this->entretiens->finish($interview))]);
    }

    /**
     * Seul l'auteur écrit dans son entretien ; le prospect doit rester
     * modifiable (archivé ou effacé entre-temps : refus de la policy).
     */
    private function assertAuteur(Request $request, Interview $interview): void
    {
        if ($interview->user_id !== $request->user()?->id) {
            throw new AccessDeniedHttpException("Cet entretien est mené par quelqu'un d'autre.");
        }
        $this->authorize('update', $interview->prospect);
    }

    /**
     * @return array<string, mixed>
     */
    private function planPour(Request $request, Project $project): array
    {
        $plan = $this->entretiens->plan($project, $request->user());

        return [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'questions' => $plan['questions']->map(fn (Question $q) => [
                ...(new QuestionResource($q))->resolve($request),
                'my_seconds' => $plan['mine'][$q->id] ?? null,
            ])->values()->all(),
            'totals' => $plan['totals'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ressource(Interview $e): array
    {
        return [
            'id' => $e->id,
            'prospect_id' => $e->prospect_id,
            'project_id' => $e->project_id,
            'user' => $e->user === null ? null : ['id' => $e->user->id, 'name' => $e->user->name],
            'started_at' => $e->started_at->toIso8601String(),
            'finished_at' => $e->finished_at?->toIso8601String(),
            'planned_seconds' => $e->planned_seconds,
            'current_question_id' => $e->current_question_id,
            'answered_count' => $e->answered_count,
            'status' => $e->status(),
        ];
    }
}
