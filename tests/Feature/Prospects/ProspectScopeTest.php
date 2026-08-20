<?php

namespace Tests\Feature\Prospects;

use App\Enums\ActivityType;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectScopeTest extends TestCase
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

    public function test_une_entreprise_ne_voit_pas_les_prospects_d_une_autre(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminA = User::factory()->adminClient()->create(['company_id' => $projetA->company_id]);

        Prospect::factory()->forProject($projetA)->count(3)->create();
        $prospectB = Prospect::factory()->forProject($projetB)->create();

        $this->actingAs($adminA)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // 404 et non 403 : ne pas révéler l'existence de la ressource.
        $this->actingAs($adminA)->getJson("/api/v1/prospects/{$prospectB->id}")->assertNotFound();
    }

    public function test_un_commercial_ne_voit_que_les_siens_et_les_non_affectes(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $collegue = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->assignedTo($commercial)->count(2)->create();
        Prospect::factory()->forProject($projet)->assignedTo($collegue)->count(3)->create();
        Prospect::factory()->forProject($projet)->unassigned()->create();

        // Les non affectés restent visibles : ce sont ceux qu'un commercial
        // peut réclamer, et les masquer les rendrait introuvables.
        $this->actingAs($commercial)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_un_manager_voit_les_prospects_de_son_equipe(): void
    {
        $projet = $this->projet();
        $manager = User::factory()->manager()->create(['company_id' => $projet->company_id]);
        $sien = User::factory()->commercial()->managedBy($manager)->create();
        $autre = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->assignedTo($sien)->count(2)->create();
        Prospect::factory()->forProject($projet)->assignedTo($autre)->count(4)->create();

        $this->actingAs($manager)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_un_commercial_ne_peut_pas_reaffecter(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $collegue = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        $prospect = Prospect::factory()->forProject($projet)->assignedTo($commercial)->create();

        // Pouvoir se réaffecter les prospects des autres contournerait la
        // répartition décidée par le manager.
        $this->actingAs($commercial)
            ->patchJson("/api/v1/prospects/{$prospect->id}/assign", ['assigned_user_id' => $collegue->id])
            ->assertForbidden();
    }

    public function test_le_super_admin_n_accede_pas_aux_donnees_commerciales(): void
    {
        $projet = $this->projet();
        Prospect::factory()->forProject($projet)->count(2)->create();

        // Son périmètre est la plateforme, pas les données de ses clients —
        // même s'il sort techniquement du cloisonnement.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/prospects')
            ->assertForbidden();
    }

    public function test_le_journal_est_cloisonne_comme_le_prospect(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminA = User::factory()->adminClient()->create(['company_id' => $projetA->company_id]);
        $prospectB = Prospect::factory()->forProject($projetB)->create();

        ProspectActivity::query()->create([
            'company_id' => $prospectB->company_id,
            'prospect_id' => $prospectB->id,
            'type' => ActivityType::NoteAdded,
            'payload' => ['note' => 'Confidentiel'],
            'occurred_at' => now(),
        ]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/prospects/{$prospectB->id}/activities")
            ->assertNotFound();
    }

    public function test_le_plafond_de_prospects_est_applique(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->state(['max_prospects' => 2]))->create();

        $projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($projet);

        $admin = User::factory()->adminClient()->forCompany($company)->create();

        foreach (['Un', 'Deux'] as $nom) {
            $this->actingAs($admin)->postJson('/api/v1/prospects', [
                'project_id' => $projet->id, 'first_name' => $nom,
            ])->assertCreated();
        }

        $this->actingAs($admin)->postJson('/api/v1/prospects', [
            'project_id' => $projet->id, 'first_name' => 'Trois',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'subscription.limit_reached')
            ->assertJsonPath('meta.resource', 'prospects');
    }
}
