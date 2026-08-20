<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProspectAnswerResource;
use App\Http\Resources\QuestionResource;
use App\Models\Prospect;
use App\Services\ActivityLogger;
use App\Services\QuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProspectAnswerController extends Controller
{
    public function __construct(
        private readonly QuestionnaireService $questionnaire,
        private readonly ActivityLogger $journal,
    ) {}

    /**
     * Questionnaire du prospect : définitions et réponses en une seule réponse.
     *
     * Les deux voyagent ensemble parce que le front en a besoin ensemble pour
     * rendre le formulaire : les envoyer séparément imposerait deux allers-
     * retours et un état intermédiaire où le formulaire est construit mais
     * vide.
     */
    public function show(Prospect $prospect): JsonResponse
    {
        $this->authorize('view', $prospect);

        return response()->json([
            'data' => [
                'questions' => QuestionResource::collection(
                    $this->questionnaire->questionsFor($prospect->project)
                )->resolve(),
                'answers' => ProspectAnswerResource::collection(
                    $this->questionnaire->answersFor($prospect)
                )->resolve(),
            ],
        ]);
    }

    /**
     * Enregistre les réponses.
     *
     * Aucune règle de validation n'est écrite ici : elles sont dérivées des
     * définitions à l'exécution — invariant n°3.
     */
    public function store(Request $request, Prospect $prospect): JsonResponse
    {
        $this->authorize('update', $prospect);

        $reponses = $request->input('answers', []);

        $enregistrees = $this->questionnaire->saveAnswers($prospect, is_array($reponses) ? $reponses : []);

        // La qualification est une interaction commerciale : elle a sa place
        // dans le journal, au même titre qu'une note ou un changement d'étape.
        $this->journal->fieldsUpdated($prospect, ['questionnaire']);

        return response()->json([
            'data' => ProspectAnswerResource::collection($enregistrees)->resolve(),
        ]);
    }
}
