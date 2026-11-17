<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Défauts orange de la revue indépendante du lot 9, côté API.
 *
 * Chacun est un point d'entrée qui échappait à une règle censée vivre « à un
 * seul endroit » : réaffectation en masse, suppression d'étape, création dans
 * un projet archivé, archivage dans un projet archivé, code des refus de
 * policy, audit de qualité.
 */
class RevueLot9Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
        $this->projet = $this->projet();
    }

    private function projet(): Project
    {
        $projet = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($projet);

        return $projet->fresh() ?? $projet;
    }

    private function archiverLeProjet(Project $projet): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => ProjectStatus::Archived->value])
            ->assertOk();
    }

    private function relire(Prospect $prospect): Prospect
    {
        return Prospect::query()->acrossAllCompanies()->withArchived()->findOrFail($prospect->id);
    }

    public function test_la_reaffectation_en_masse_ignore_les_fiches_effacees_et_les_projets_archives(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();
        $normal = Prospect::factory()->forProject($this->projet)->unassigned()->create();
        $efface = Prospect::factory()->forProject($this->projet)->unassigned()->create();
        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$efface->id}/erase")->assertOk();

        $projetArchive = $this->projet();
        $dansProjetArchive = Prospect::factory()->forProject($projetArchive)->unassigned()->create();
        $this->archiverLeProjet($projetArchive);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/prospects/bulk-assign', [
                'prospect_ids' => [$normal->id, $efface->id, $dansProjetArchive->id],
                'assigned_user_id' => $commercial->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.reassigned', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertNull($this->relire($efface)->assigned_user_id);
        $this->assertNull($this->relire($dansProjetArchive)->assigned_user_id);
    }

    public function test_supprimer_une_etape_occupee_par_des_archives_demande_un_repli_et_les_deplace(): void
    {
        [$premiere, $seconde] = $this->projet->stages()->orderBy('position')->take(2)->get()->all();
        $archive = Prospect::factory()->forProject($this->projet)->atStage($seconde)->create();
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$archive->id}/archive", ['archived' => true])
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$seconde->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('fallback_stage_id');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$seconde->id}", ['fallback_stage_id' => $premiere->id])
            ->assertNoContent();

        $this->assertSame($premiere->id, $this->relire($archive)->stage_id);
    }

    public function test_creer_un_prospect_dans_un_projet_archive_repond_archive_read_only(): void
    {
        $this->archiverLeProjet($this->projet);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/prospects', ['project_id' => $this->projet->id, 'first_name' => 'Hery'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'archive.read_only');
    }

    public function test_un_prospect_d_un_projet_archive_ne_s_archive_ni_ne_se_desarchive(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();
        $this->archiverLeProjet($this->projet);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'archive.read_only');
    }

    public function test_un_refus_de_policy_porte_le_code_auth_forbidden(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();
        $manager = User::factory()->manager()->forCompany($this->company)->create();

        $this->actingAs($manager)
            ->postJson("/api/v1/prospects/{$prospect->id}/erase")
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.forbidden');
    }

    public function test_l_audit_de_qualite_ignore_les_fiches_effacees(): void
    {
        $efface = Prospect::factory()->forProject($this->projet)->create();
        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$efface->id}/erase")->assertOk();

        /** @var list<array{id: int}> $exemples */
        $exemples = $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->projet->id}/data-quality")
            ->assertOk()
            ->json('data.sans_coordonnee.exemples');

        $this->assertNotContains($efface->id, array_column($exemples, 'id'));
    }

    public function test_l_audit_de_qualite_ne_fait_pas_une_requete_par_groupe_de_doublons(): void
    {
        for ($i = 0; $i < 12; $i++) {
            Prospect::factory()->forProject($this->projet)->count(2)->create([
                'phone_normalized' => '+2613400000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $requetes = 0;
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            if (str_contains($requete->sql, 'prospects')) {
                $requetes++;
            }
        });

        $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->projet->id}/data-quality")
            ->assertOk()
            ->assertJsonPath('data.doublons.groupes', 12)
            ->assertJsonPath('data.doublons.prospects', 24)
            ->assertJsonCount(5, 'data.doublons.exemples');

        $this->assertLessThan(15, $requetes, "{$requetes} requêtes sur les prospects pour 12 groupes");
    }
}
