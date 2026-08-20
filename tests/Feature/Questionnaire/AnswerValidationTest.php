<?php

namespace Tests\Feature\Questionnaire;

use App\Enums\QuestionType;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectAnswer;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La validation d'une réponse est **dérivée du type à l'exécution** — il
 * n'existe aucune règle statique par champ (invariant n°3).
 */
class AnswerValidationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private Prospect $prospect;

    /**
     * Les positions sont attribuées séquentiellement plutôt que par la
     * factory, dont le tirage aléatoire rendrait l'ordre d'affichage
     * imprévisible — or c'est précisément ce qu'un des tests vérifie.
     */
    private int $position = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
        $this->prospect = Prospect::factory()->forProject($this->project)->create();
    }

    private function question(QuestionType $type, bool $requise = false): Question
    {
        $question = Question::factory()
            ->forProject($this->project)
            ->ofType($type)
            ->create([
                'required' => $requise,
                'label' => $type->label(),
                'position' => $this->position++,
            ]);

        if ($type->requiresOptions()) {
            foreach ([['Alpha', 'alpha'], ['Beta', 'beta']] as $index => [$libelle, $valeur]) {
                QuestionOption::query()->create([
                    'company_id' => $question->company_id,
                    'question_id' => $question->id,
                    'label' => $libelle,
                    'value' => $valeur,
                    'position' => $index + 1,
                ]);
            }
        }

        return $question->load('options');
    }

    /**
     * `TestResponse` est générique sur la classe de réponse sous-jacente ;
     * l'annotation la précise, faute de quoi l'analyse statique la refuse.
     *
     * @return TestResponse<Response>
     */
    private function repondre(Question $question, mixed $valeur): TestResponse
    {
        return $this->actingAs($this->admin)->putJson(
            "/api/v1/prospects/{$this->prospect->id}/questionnaire",
            ['answers' => [$question->id => $valeur]],
        );
    }

    private function valeurEnregistree(Question $question): mixed
    {
        return ProspectAnswer::query()
            ->where('prospect_id', $this->prospect->id)
            ->where('question_id', $question->id)
            ->value('value');
    }

    /**
     * @return array<string, array{QuestionType, mixed, mixed}>
     */
    public static function reponsesValides(): array
    {
        return [
            'texte' => [QuestionType::Text, 'Bonjour', 'Bonjour'],
            'texte long' => [QuestionType::LongText, "Deux\nlignes", "Deux\nlignes"],
            'nombre' => [QuestionType::Number, '42', 42],
            'email' => [QuestionType::Email, 'a@b.mg', 'a@b.mg'],
            'telephone' => [QuestionType::Phone, '034 12 345 67', '034 12 345 67'],
            'date' => [QuestionType::Date, '2026-08-20', '2026-08-20'],
            'liste' => [QuestionType::Dropdown, 'alpha', 'alpha'],
            'choix unique' => [QuestionType::SingleChoice, 'beta', 'beta'],
            'oui/non' => [QuestionType::YesNo, '1', true],
            'case a cocher' => [QuestionType::Checkbox, '0', false],
        ];
    }

    #[DataProvider('reponsesValides')]
    public function test_une_reponse_valide_est_enregistree_avec_le_bon_type(
        QuestionType $type,
        mixed $saisie,
        mixed $attendue,
    ): void {
        $question = $this->question($type);

        $this->repondre($question, $saisie)->assertOk();

        // Un formulaire HTML transmet tout en chaînes : sans conversion, un
        // nombre serait stocké « 42 » et une case cochée « on », et le front
        // devrait deviner le type à la relecture.
        $this->assertSame($attendue, $this->valeurEnregistree($question));
    }

    public function test_un_choix_multiple_stocke_un_tableau(): void
    {
        $question = $this->question(QuestionType::MultipleChoice);

        $this->repondre($question, ['alpha', 'beta'])->assertOk();

        $this->assertSame(['alpha', 'beta'], $this->valeurEnregistree($question));
    }

    /**
     * @return array<string, array{QuestionType, mixed}>
     */
    public static function reponsesInvalides(): array
    {
        return [
            'nombre non numerique' => [QuestionType::Number, 'quarante-deux'],
            'email malforme' => [QuestionType::Email, 'pas-une-adresse'],
            'date invalide' => [QuestionType::Date, '32/13/2026'],
            'option inconnue' => [QuestionType::Dropdown, 'gamma'],
            'choix unique inconnu' => [QuestionType::SingleChoice, 'gamma'],
        ];
    }

    #[DataProvider('reponsesInvalides')]
    public function test_une_reponse_invalide_est_refusee(QuestionType $type, mixed $saisie): void
    {
        $question = $this->question($type);

        $this->repondre($question, $saisie)
            ->assertUnprocessable()
            ->assertJsonValidationErrors("answers.{$question->id}");
    }

    public function test_un_choix_multiple_refuse_une_option_inconnue(): void
    {
        $question = $this->question(QuestionType::MultipleChoice);

        // La règle posée sur le tableau ne dit rien de son contenu : chaque
        // élément doit être validé séparément.
        $this->repondre($question, ['alpha', 'gamma'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors("answers.{$question->id}.1");
    }

    public function test_une_question_obligatoire_bloque_une_reponse_vide(): void
    {
        $question = $this->question(QuestionType::Text, requise: true);

        $reponse = $this->repondre($question, null)->assertUnprocessable();

        // La clé d'erreur est littérale — « answers.42 » — et non un chemin
        // imbriqué : on lit donc le sac d'erreurs entier.
        $erreurs = $reponse->json('errors');

        // Le message porte le libellé de la question, pas « answers.42 » :
        // sans cela il serait incompréhensible pour l'utilisateur.
        $this->assertStringContainsString(
            $question->label,
            $erreurs["answers.{$question->id}"][0]
        );
    }

    public function test_une_reponse_facultative_effacee_est_supprimee(): void
    {
        $question = $this->question(QuestionType::Text);

        $this->repondre($question, 'Première saisie')->assertOk();
        $this->assertDatabaseCount('prospect_answers', 1);

        // Effacer une réponse doit la retirer, pas enregistrer une chaîne vide
        // qui compterait comme « renseignée » dans les statistiques.
        $this->repondre($question, null)->assertOk();
        $this->assertDatabaseCount('prospect_answers', 0);
    }

    public function test_une_seconde_saisie_remplace_la_premiere(): void
    {
        $question = $this->question(QuestionType::Text);

        $this->repondre($question, 'Avant')->assertOk();
        $this->repondre($question, 'Après')->assertOk();

        $this->assertDatabaseCount('prospect_answers', 1);
        $this->assertSame('Après', $this->valeurEnregistree($question));
    }

    public function test_le_questionnaire_renvoie_definitions_et_reponses_ensemble(): void
    {
        $texte = $this->question(QuestionType::Text);
        $liste = $this->question(QuestionType::Dropdown);

        $this->repondre($liste, 'alpha')->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/prospects/{$this->prospect->id}/questionnaire")
            ->assertOk()
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonCount(1, 'data.answers')
            // Le libellé lisible est résolu côté serveur : sans lui, le front
            // devrait rapprocher chaque valeur de sa liste d'options.
            ->assertJsonPath('data.answers.0.display', 'Alpha')
            ->assertJsonPath('data.questions.0.id', $texte->id);
    }

    public function test_les_reponses_sont_cloisonnees_par_entreprise(): void
    {
        $autreCompany = Company::factory()->create();
        Subscription::factory()->for($autreCompany)->for(Plan::factory()->enterprise())->create();
        $autreAdmin = User::factory()->adminClient()->forCompany($autreCompany)->create();

        $question = $this->question(QuestionType::Text);
        $this->repondre($question, 'Confidentiel')->assertOk();

        $this->actingAs($autreAdmin)
            ->getJson("/api/v1/prospects/{$this->prospect->id}/questionnaire")
            ->assertNotFound();
    }
}
