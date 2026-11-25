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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Entretien guidé — lot 13, contrat `cadrage/contrats/LOT-13_Entretien-guide.md`.
 *
 * Le cœur du lot : une réponse donnée en cours d'entretien est gardée même si
 * une question obligatoire est encore vide ; l'obligation ne se contrôle qu'à
 * la fin. Une simulation n'écrit rien, sauf les durées personnelles.
 */
class InterviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    private User $commercial;

    private Prospect $prospect;

    private Question $obligatoire;

    private Question $libre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:30:00', 'UTC'));

        $this->company = Company::factory()->create(['timezone' => 'Indian/Antananarivo']);
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->project = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->commercial = User::factory()->commercial()->forCompany($this->company)->create(['name' => 'Malala Rasoa']);
        $this->project->members()->attach($this->commercial->id);
        $this->prospect = Prospect::factory()->forProject($this->project)->assignedTo($this->commercial)->create();

        $this->obligatoire = Question::factory()->forProject($this->project)->ofType(QuestionType::Number)->required()->create(['label' => 'Nombre de personnes', 'position' => 1, 'planned_seconds' => 30]);
        $this->libre = Question::factory()->forProject($this->project)->ofType(QuestionType::LongText)->create(['label' => 'Besoins', 'position' => 2, 'planned_seconds' => 120]);
    }

    private function demarrer(?User $qui = null): int
    {
        return (int) $this->actingAs($qui ?? $this->commercial)
            ->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertCreated()->json('data.id');
    }

    public function test_une_reponse_en_cours_d_entretien_est_gardee_malgre_une_obligatoire_vide(): void
    {
        $id = $this->demarrer();

        $this->putJson("/api/v1/interviews/{$id}/answers/{$this->libre->id}", ['value' => 'Couverture dentaire'])
            ->assertOk()->assertJsonPath('data.answered_count', 1);

        $this->assertDatabaseHas('prospect_answers', ['prospect_id' => $this->prospect->id, 'question_id' => $this->libre->id]);
        $this->getJson("/api/v1/prospects/{$this->prospect->id}/interviews/current")
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.current_question_id', $this->libre->id);
    }

    public function test_terminer_avec_une_obligatoire_vide_est_refuse(): void
    {
        $id = $this->demarrer();

        $this->postJson("/api/v1/interviews/{$id}/finish")
            ->assertUnprocessable()->assertJsonValidationErrors("answers.{$this->obligatoire->id}");
        $this->assertDatabaseHas('interviews', ['id' => $id, 'finished_at' => null]);
    }

    public function test_terminer_clot_l_entretien_et_ecrit_au_journal(): void
    {
        $id = $this->demarrer();
        $this->putJson("/api/v1/interviews/{$id}/answers/{$this->obligatoire->id}", ['value' => 4])->assertOk();
        $this->travel(9)->minutes();

        $this->postJson("/api/v1/interviews/{$id}/finish")->assertOk()->assertJsonPath('data.status', 'finished');

        $activite = DB::table('prospect_activities')->where('prospect_id', $this->prospect->id)->where('type', 'interview_completed')->first();
        $this->assertNotNull($activite);
        $payload = json_decode((string) $activite->payload, true);
        $this->assertSame(540, $payload['duration_seconds']);
        $this->assertSame(150, $payload['planned_seconds']);
    }

    public function test_une_reponse_invalide_pour_son_type_est_refusee(): void
    {
        $id = $this->demarrer();

        $this->putJson("/api/v1/interviews/{$id}/answers/{$this->obligatoire->id}", ['value' => 'quatre'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    public function test_demarrer_sur_un_prospect_archive_est_refuse(): void
    {
        // Par la règle de l'application, pas par une écriture directe.
        $admin = User::factory()->adminClient()->forCompany($this->company)->create();
        $this->actingAs($admin)->patchJson("/api/v1/prospects/{$this->prospect->id}/archive", ['archived' => true])->assertOk();

        $this->actingAs($this->commercial)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertUnprocessable()->assertJsonPath('code', 'archive.read_only');
    }

    public function test_demarrer_avec_un_abonnement_suspendu_est_refuse(): void
    {
        $this->company->subscription()->delete();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->suspended()->create();

        $this->actingAs($this->commercial)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertForbidden()->assertJsonPath('code', 'subscription.writes_blocked');
    }

    public function test_un_questionnaire_vide_est_refuse(): void
    {
        Question::query()->where('project_id', $this->project->id)->delete();

        $this->actingAs($this->commercial)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertUnprocessable()->assertJsonPath('code', 'interview.empty_questionnaire');
    }

    public function test_un_seul_entretien_ouvert_par_prospect(): void
    {
        $id = $this->demarrer();

        // Reprise par son auteur : le même entretien.
        $this->actingAs($this->commercial)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertOk()->assertJsonPath('data.id', $id);

        $admin = User::factory()->adminClient()->forCompany($this->company)->create();
        $this->actingAs($admin)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")
            ->assertStatus(409)->assertJsonPath('code', 'interview.already_open')->assertJsonPath('meta.user', 'Malala Rasoa');
    }

    public function test_un_entretien_de_plus_de_24h_est_abandonne(): void
    {
        $id = $this->demarrer();
        $this->travel(25)->hours();

        $this->putJson("/api/v1/interviews/{$id}/answers/{$this->libre->id}", ['value' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'interview.closed');
        $this->getJson("/api/v1/prospects/{$this->prospect->id}/interviews/current")->assertOk()->assertJsonPath('data', null);
        $this->assertNotSame($id, $this->demarrer());
    }

    public function test_seul_l_auteur_ecrit_dans_l_entretien(): void
    {
        $id = $this->demarrer();
        $admin = User::factory()->adminClient()->forCompany($this->company)->create();

        $this->actingAs($admin)->putJson("/api/v1/interviews/{$id}/answers/{$this->libre->id}", ['value' => 'x'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/interviews/{$id}/finish")->assertForbidden();
    }

    public function test_un_commercial_ne_demarre_pas_sur_le_prospect_d_un_autre(): void
    {
        $autre = User::factory()->commercial()->forCompany($this->company)->create();
        $this->project->members()->attach($autre->id);

        $this->actingAs($autre)->postJson("/api/v1/prospects/{$this->prospect->id}/interviews")->assertForbidden();
    }

    public function test_une_entreprise_b_ne_voit_ni_ne_reprend_l_entretien_de_a(): void
    {
        $id = $this->demarrer();
        $autre = Company::factory()->create();
        Subscription::factory()->for($autre)->for(Plan::factory()->enterprise())->create();
        $adminB = User::factory()->adminClient()->forCompany($autre)->create();

        $this->actingAs($adminB)->getJson("/api/v1/prospects/{$this->prospect->id}/interviews/current")->assertNotFound();
        $this->actingAs($adminB)->putJson("/api/v1/interviews/{$id}/answers/{$this->libre->id}", ['value' => 'x'])->assertNotFound();
        $this->actingAs($adminB)->getJson("/api/v1/projects/{$this->project->id}/interview-plan")->assertNotFound();
    }

    public function test_le_plan_donne_les_durees_prevues_et_personnelles(): void
    {
        $this->actingAs($this->commercial)->putJson("/api/v1/projects/{$this->project->id}/my-timings", [
            'timings' => [['question_id' => $this->libre->id, 'seconds' => 93]],
        ])->assertOk();

        $plan = $this->getJson("/api/v1/projects/{$this->project->id}/interview-plan")->assertOk()->json('data');

        // 93 s arrondies à 95 ; total : 30 prévues + 95 personnelles.
        $this->assertSame([30, 120], array_column($plan['questions'], 'planned_seconds'));
        $this->assertSame([null, 95], array_column($plan['questions'], 'my_seconds'));
        $this->assertSame(['planned_seconds' => 150, 'my_seconds' => 125], $plan['totals']);
    }

    public function test_les_durees_personnelles_ne_touchent_pas_un_autre_commercial(): void
    {
        $this->actingAs($this->commercial)->putJson("/api/v1/projects/{$this->project->id}/my-timings", [
            'timings' => [['question_id' => $this->libre->id, 'seconds' => 60]],
        ])->assertOk();
        $autre = User::factory()->commercial()->forCompany($this->company)->create();
        $this->project->members()->attach($autre->id);

        $plan = $this->actingAs($autre)->getJson("/api/v1/projects/{$this->project->id}/interview-plan")->json('data');
        $this->assertSame([null, null], array_column($plan['questions'], 'my_seconds'));

        $this->actingAs($this->commercial)->deleteJson("/api/v1/projects/{$this->project->id}/my-timings")->assertNoContent();
        $this->assertDatabaseCount('question_timings', 0);
    }

    public function test_une_simulation_n_ecrit_ni_reponse_ni_journal(): void
    {
        $activites = DB::table('prospect_activities')->count();

        // Une simulation ne lit que le plan et ne garde que des durées.
        $this->actingAs($this->commercial)->getJson("/api/v1/projects/{$this->project->id}/interview-plan")->assertOk();
        $this->putJson("/api/v1/projects/{$this->project->id}/my-timings", [
            'timings' => [['question_id' => $this->obligatoire->id, 'seconds' => 40]],
        ])->assertOk();

        $this->assertDatabaseCount('prospect_answers', 0);
        $this->assertDatabaseCount('interviews', 0);
        $this->assertSame($activites, DB::table('prospect_activities')->count());
    }

    public function test_une_duree_hors_bornes_est_refusee(): void
    {
        $admin = User::factory()->adminClient()->forCompany($this->company)->create();

        $this->actingAs($admin)->patchJson("/api/v1/questions/{$this->libre->id}", ['planned_seconds' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('planned_seconds');
        $this->actingAs($admin)->patchJson("/api/v1/questions/{$this->libre->id}", ['planned_seconds' => 90])
            ->assertOk()->assertJsonPath('data.planned_seconds', 90);
    }
}
