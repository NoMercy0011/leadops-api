<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\Project;
use App\Models\Question;
use App\Services\QuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    public function __construct(private readonly QuestionnaireService $questionnaire) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return QuestionResource::collection($this->questionnaire->questionsFor($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        // Le questionnaire engage la qualification de toute l'équipe : sa
        // définition relève de l'Admin Client, comme le pipeline.
        $this->authorize('managePipeline', $project);

        $donnees = $this->valider($request);

        $question = $this->questionnaire->createQuestion($project, $donnees);

        return (new QuestionResource($question))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(Request $request, Question $question): QuestionResource
    {
        $this->authorize('managePipeline', $question->project);

        $donnees = $this->valider($request, partiel: true);

        return new QuestionResource(
            $this->questionnaire->updateQuestion($question, $donnees)
        );
    }

    public function reorder(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('managePipeline', $project);

        $donnees = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*' => ['integer'],
        ]);

        $this->questionnaire->reorder($project, $donnees['questions']);

        return QuestionResource::collection($this->questionnaire->questionsFor($project));
    }

    /**
     * Suppression douce uniquement.
     *
     * Une suppression dure détruirait les réponses déjà collectées, donc
     * l'historique de qualification et les statistiques passées. La question
     * disparaît du formulaire ; les réponses restent lisibles sur les fiches.
     */
    public function destroy(Question $question): JsonResponse
    {
        $this->authorize('managePipeline', $question->project);

        $question->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, bool $partiel = false): array
    {
        $presence = $partiel ? 'sometimes' : 'required';

        $donnees = $request->validate([
            'type' => [$presence, Rule::enum(QuestionType::class)],
            'label' => [$presence, 'string', 'max:255'],
            'help' => ['sometimes', 'nullable', 'string', 'max:255'],
            'required' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'options.*.value' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->assertOptionsCoherentes($request, $donnees, $partiel);

        return $donnees;
    }

    /**
     * Un type à options sans options produirait un champ impossible à remplir,
     * et le formulaire du prospect serait bloqué sans que la cause soit visible
     * depuis la fiche.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function assertOptionsCoherentes(Request $request, array $donnees, bool $partiel): void
    {
        if (! isset($donnees['type'])) {
            return;
        }

        $type = QuestionType::from($donnees['type']);

        if (! $type->requiresOptions()) {
            return;
        }

        // En mise à jour partielle, l'absence de clé `options` signifie « ne
        // pas y toucher » : les options existantes restent en place.
        if ($partiel && ! $request->has('options')) {
            return;
        }

        if (count($donnees['options'] ?? []) < 2) {
            throw ValidationException::withMessages([
                'options' => "Un type « {$type->label()} » demande au moins deux options.",
            ]);
        }
    }
}
