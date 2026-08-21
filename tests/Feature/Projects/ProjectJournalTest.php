<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Question;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Journal de configuration d'un projet.
 *
 * Le journal existant retrace ce qui arrive à un prospect. Celui-ci retrace ce
 * qui arrive au *cadre* — pipeline, questionnaire, équipe, statut.
 *
 * L'enjeu dépasse l'audit : renommer « Qualifié » en « Étude médicale » change
 * rétroactivement le sens de tous les prospects qui y sont passés, et un
 * rapport comparé d'un trimestre à l'autre devient faux sans que rien ne le
 * signale. L'événement n'appartient à aucun prospect, donc aucun journal
 * existant ne pouvait le porter.
 */
class ProjectJournalTest extends TestCase
{
    use RefreshDatabase;

    private function projet(): Project
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($project);

        return $project->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(User $viewer, Project $projet): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->actingAs($viewer)
            ->getJson("/api/v1/projects/{$projet->id}/activities")
            ->assertOk()
            ->json('data');

        return $lignes;
    }

    public function test_un_renommage_d_etape_est_trace_et_signale(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etape = $projet->stages[2];

        $this->actingAs($admin)
            ->patchJson("/api/v1/stages/{$etape->id}", ['name' => 'Étude médicale'])
            ->assertOk();

        $journal = $this->journal($admin, $projet);

        $this->assertSame('stage_updated', $journal[0]['type']);
        $this->assertSame($etape->name, $journal[0]['payload']['de']);
        $this->assertSame('Étude médicale', $journal[0]['payload']['vers']);

        // Ce drapeau distingue les changements qui réinterprètent l'historique
        // d'une simple modification de forme.
        $this->assertTrue($journal[0]['reinterprete_le_passe']);
    }

    public function test_une_modification_sans_renommage_n_ecrit_rien(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etape = $projet->stages[2];

        // Changer la couleur passe par le même point d'entrée. Journaliser
        // « de Qualifié vers Qualifié » noierait les vrais renommages, qui sont
        // précisément ce que ce journal existe pour tracer.
        $this->actingAs($admin)
            ->patchJson("/api/v1/stages/{$etape->id}", ['color' => 'violet'])
            ->assertOk();

        $this->assertSame(0, ProjectActivity::query()->where('project_id', $projet->id)->count());
    }

    public function test_le_changement_de_statut_est_distinct_de_la_modification(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => 'archived'])
            ->assertOk();

        $journal = $this->journal($admin, $projet);

        // « Archivé » et « renommé » n'appellent pas la même lecture : les
        // mêler obligerait l'écran à fouiller le payload pour les distinguer.
        $this->assertSame('status_changed', $journal[0]['type']);
        $this->assertSame('Actif', $journal[0]['payload']['de']);
        $this->assertSame('Archivé', $journal[0]['payload']['vers']);
    }

    public function test_seuls_les_champs_modifies_sont_conserves(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$projet->id}", [
                'target' => 'Familles',
                'product' => $projet->product,
            ])
            ->assertOk();

        $journal = $this->journal($admin, $projet);

        // Enregistrer l'objet entier noierait le changement utile dans une
        // liste de valeurs identiques.
        $this->assertSame(['target'], array_keys($journal[0]['payload']['champs']));
    }

    public function test_le_retrait_d_une_question_est_trace(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $question = Question::factory()->forProject($projet)->create(['label' => 'Revenu mensuel']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/questions/{$question->id}")
            ->assertNoContent();

        $journal = $this->journal($admin, $projet);

        $this->assertSame('question_removed', $journal[0]['type']);
        $this->assertSame('Revenu mensuel', $journal[0]['payload']['question']);
        $this->assertTrue($journal[0]['reinterprete_le_passe']);
    }

    public function test_les_mouvements_d_equipe_sont_traces_par_nom(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $commercial = User::factory()->commercial()->create([
            'company_id' => $projet->company_id,
            'name' => 'Hanta Rakotomalala',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/projects/{$projet->id}/members", ['member_ids' => [$commercial->id]])
            ->assertOk();

        $journal = $this->journal($admin, $projet);

        // Par nom et non par identifiant : le journal doit rester lisible après
        // la suppression du compte concerné.
        $this->assertSame('members_changed', $journal[0]['type']);
        $this->assertSame(['Hanta Rakotomalala'], $journal[0]['payload']['entrants']);
    }

    public function test_une_equipe_inchangee_n_ecrit_rien(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $projet->members()->sync([$commercial->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/projects/{$projet->id}/members", ['member_ids' => [$commercial->id]])
            ->assertOk();

        $this->assertSame(0, ProjectActivity::query()->where('project_id', $projet->id)->count());
    }

    public function test_un_commercial_n_accede_pas_au_journal(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $projet->members()->attach($commercial);

        $this->actingAs($commercial)
            ->getJson("/api/v1/projects/{$projet->id}/activities")
            ->assertForbidden();
    }

    public function test_une_autre_entreprise_n_atteint_pas_ce_journal(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminB = User::factory()->adminClient()->create(['company_id' => $projetB->company_id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/projects/{$projetA->id}/activities")
            ->assertNotFound();
    }
}
