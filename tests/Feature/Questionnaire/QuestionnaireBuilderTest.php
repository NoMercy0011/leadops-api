<?php

namespace Tests\Feature\Questionnaire;

use App\Enums\QuestionType;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Question;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use App\Services\QuestionnaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant n°3 : le questionnaire se configure depuis l'interface, sans
 * déploiement. Le critère de sortie du lot est de couvrir les onze types sans
 * toucher au code.
 */
class QuestionnaireBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
    }

    public function test_les_onze_types_se_creent_sans_toucher_au_code(): void
    {
        foreach (QuestionType::ordered() as $index => $type) {
            $charge = [
                'type' => $type->value,
                'label' => "Question {$type->label()}",
                'required' => false,
            ];

            if ($type->requiresOptions()) {
                $charge['options'] = [
                    ['label' => 'Premier choix'],
                    ['label' => 'Second choix'],
                ];
            }

            $this->actingAs($this->admin)
                ->postJson("/api/v1/projects/{$this->project->id}/questions", $charge)
                ->assertCreated()
                ->assertJsonPath('data.type', $type->value)
                ->assertJsonPath('data.position', $index + 1);
        }

        $this->assertSame(
            count(QuestionType::ordered()),
            Question::query()->acrossAllCompanies()->count()
        );
    }

    public function test_un_type_a_options_exige_au_moins_deux_choix(): void
    {
        // Une liste déroulante à une seule option produit un champ impossible
        // à remplir, et le formulaire du prospect se bloque sans que la cause
        // soit visible depuis la fiche.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/projects/{$this->project->id}/questions", [
                'type' => QuestionType::Dropdown->value,
                'label' => 'Situation familiale',
                'options' => [['label' => 'Célibataire']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_le_type_d_une_question_repondue_ne_change_plus(): void
    {
        $question = Question::factory()
            ->forProject($this->project)
            ->ofType(QuestionType::Text)
            ->create(['label' => 'Besoin principal']);

        $prospect = Prospect::factory()->forProject($this->project)->create();

        app(QuestionnaireService::class)->saveAnswers($prospect, [
            $question->id => 'Couverture hospitalisation',
        ]);

        // Un « texte » devenu « nombre » rendrait les réponses existantes
        // ininterprétables et la validation les rejetterait toutes.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/questions/{$question->id}", [
                'type' => QuestionType::Number->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        // Le libellé, lui, reste modifiable : le corriger n'invalide rien.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/questions/{$question->id}", [
                'label' => 'Besoin principal exprimé',
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Besoin principal exprimé');
    }

    public function test_la_suppression_est_douce_et_preserve_les_reponses(): void
    {
        $question = Question::factory()->forProject($this->project)->create();
        $prospect = Prospect::factory()->forProject($this->project)->create();

        app(QuestionnaireService::class)->saveAnswers($prospect, [
            $question->id => 'Une réponse à conserver',
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/questions/{$question->id}")
            ->assertNoContent();

        // La question disparaît du formulaire…
        $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->project->id}/questions")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // …mais la réponse collectée reste en base : la détruire effacerait
        // l'historique de qualification et les statistiques passées.
        $this->assertDatabaseHas('prospect_answers', [
            'prospect_id' => $prospect->id,
            'question_id' => $question->id,
        ]);
        $this->assertSoftDeleted('questions', ['id' => $question->id]);
    }

    public function test_le_reordonnancement_exige_une_permutation_exacte(): void
    {
        $questions = Question::factory()->forProject($this->project)->count(4)->create();
        $ids = $questions->pluck('id')->all();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->project->id}/questions/reorder", [
                'questions' => array_reverse($ids),
            ])
            ->assertOk();

        $this->assertSame(
            array_reverse($ids),
            Question::query()->where('project_id', $this->project->id)->orderBy('position')->pluck('id')->all()
        );

        $partiel = $ids;
        array_pop($partiel);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->project->id}/questions/reorder", [
                'questions' => $partiel,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions');
    }

    public function test_renommer_une_option_n_invalide_pas_les_reponses(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/projects/{$this->project->id}/questions", [
                'type' => QuestionType::SingleChoice->value,
                'label' => 'Tranche d\'âge',
                'options' => [
                    ['label' => 'Moins de 30 ans', 'value' => 'moins-30'],
                    ['label' => '30 ans et plus', 'value' => 'plus-30'],
                ],
            ])->assertCreated();

        $questionId = $reponse->json('data.id');
        $prospect = Prospect::factory()->forProject($this->project)->create();

        app(QuestionnaireService::class)->saveAnswers($prospect, [$questionId => 'moins-30']);

        // Le libellé change, la valeur ne bouge pas : c'est elle que la
        // réponse référence.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/questions/{$questionId}", [
                'options' => [
                    ['label' => '18-29 ans', 'value' => 'moins-30'],
                    ['label' => '30 ans et plus', 'value' => 'plus-30'],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/prospects/{$prospect->id}/questionnaire")
            ->assertOk()
            ->assertJsonPath('data.answers.0.value', 'moins-30')
            ->assertJsonPath('data.answers.0.display', '18-29 ans');
    }

    public function test_un_commercial_ne_configure_pas_le_questionnaire(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->project->company)->create();
        $this->project->members()->attach($commercial);

        // Il remplit le questionnaire, il ne le définit pas : sa définition
        // engage la qualification de toute l'équipe.
        $this->actingAs($commercial)
            ->postJson("/api/v1/projects/{$this->project->id}/questions", [
                'type' => QuestionType::Text->value,
                'label' => 'Ma question',
            ])
            ->assertForbidden();
    }

    public function test_le_questionnaire_d_une_autre_entreprise_est_inatteignable(): void
    {
        $autreCompany = Company::factory()->create();
        Subscription::factory()->for($autreCompany)->for(Plan::factory()->enterprise())->create();
        $autreProjet = Project::factory()->forCompany($autreCompany)->create();
        $questionEtrangere = Question::factory()->forProject($autreProjet)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/questions/{$questionEtrangere->id}", ['label' => 'Piraté'])
            ->assertNotFound();
    }
}
