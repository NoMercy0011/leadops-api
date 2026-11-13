<?php

namespace Tests\Feature\Subscription;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plafonds d'abonnement face à l'archivage — lot 9, règles R1 et R2.
 *
 * Les deux règles sont asymétriques, et c'est voulu (décisions D1 et D4) :
 *
 * - un prospect archivé **compte** dans le plafond. Sinon, archiver puis
 *   recréer permettrait de dépasser le plan sans limite ;
 * - un projet archivé **ne compte pas**. Un projet ne se supprime plus : sans
 *   place libérée, un client Starter resterait bloqué à vie à deux projets.
 *   C'est alors la réactivation qui contrôle le plafond.
 */
class ArchivedQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private function entreprise(Plan $plan): void
    {
        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for($plan)->create();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
    }

    private function projet(): Project
    {
        $projet = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($projet);

        return $projet->fresh() ?? $projet;
    }

    public function test_archiver_un_prospect_ne_libere_pas_de_place_dans_le_plafond(): void
    {
        $this->entreprise(Plan::factory()->create(['max_prospects' => 2]));
        $projet = $this->projet();
        $prospects = Prospect::factory()->forProject($projet)->count(2)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospects[0]->id}/archive", ['archived' => true])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/prospects', [
                'project_id' => $projet->id,
                'first_name' => 'Hery',
                'phone' => '+261341112233',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subscription.limit_reached')
            ->assertJsonPath('meta.current', 2);
    }

    public function test_la_consommation_affichee_compte_les_prospects_archives(): void
    {
        $this->entreprise(Plan::factory()->create(['max_prospects' => 10]));
        $projet = $this->projet();
        $prospects = Prospect::factory()->forProject($projet)->count(3)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospects[0]->id}/archive", ['archived' => true])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.usage.prospects.used', 3);
    }

    public function test_archiver_un_projet_libere_sa_place(): void
    {
        $this->entreprise(Plan::factory()->create(['max_projects' => 2]));
        $ancien = $this->projet();
        $this->projet();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$ancien->id}", ['status' => ProjectStatus::Archived->value])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.usage.projects.used', 1);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/projects', ['name' => 'Assurance Auto'])
            ->assertCreated();
    }

    public function test_reactiver_un_projet_au_dela_du_plafond_est_refuse(): void
    {
        $this->entreprise(Plan::factory()->create(['max_projects' => 2]));
        $ancien = $this->projet();
        $this->projet();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$ancien->id}", ['status' => ProjectStatus::Archived->value])
            ->assertOk();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/projects', ['name' => 'Assurance Auto'])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$ancien->id}", ['status' => ProjectStatus::Active->value])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subscription.limit_reached')
            ->assertJsonPath('meta.resource', 'projects');

        $this->assertSame(ProjectStatus::Archived, $ancien->fresh()?->status);
    }

    public function test_reactiver_un_projet_sous_le_plafond_est_accepte(): void
    {
        $this->entreprise(Plan::factory()->create(['max_projects' => 2]));
        $ancien = $this->projet();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$ancien->id}", ['status' => ProjectStatus::Archived->value])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$ancien->id}", ['status' => ProjectStatus::Active->value])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectStatus::Active->value);
    }
}
