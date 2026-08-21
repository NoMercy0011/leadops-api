<?php

namespace Tests\Feature\Prospects;

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
 * Le bloc `summary` de la liste des prospects.
 *
 * Il existe parce que le front ne peut pas le calculer : il ne reçoit qu'une
 * page, et compter les relances en retard sur vingt-cinq lignes donnerait un
 * chiffre faux dès la seconde page — faux en silence, ce qui est le pire cas
 * pour un indicateur censé déclencher une action.
 *
 * Ces tests vérifient les deux propriétés qui font sa valeur : il porte sur le
 * filtre entier et non sur la page, et il respecte le périmètre de rôle.
 */
class ProspectSummaryTest extends TestCase
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

    public function test_le_resume_porte_sur_le_filtre_entier_et_non_sur_la_page(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->count(30)->create([
            'next_action_at' => now()->subDay(),
        ]);

        $reponse = $this->actingAs($admin)
            ->getJson('/api/v1/prospects?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        // La page en montre cinq, le résumé en compte trente.
        $reponse->assertJsonPath('summary.total', 30);
        $reponse->assertJsonPath('summary.en_retard', 30);
    }

    public function test_une_relance_depassee_sur_un_dossier_clos_n_est_pas_en_retard(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->create([
            'next_action_at' => now()->subWeek(),
        ]);

        // Converti et perdu portent tous deux une relance dépassée : plus rien
        // n'est à faire dessus, les compter gonflerait l'indicateur sans fin.
        Prospect::factory()->forProject($projet)->create([
            'next_action_at' => now()->subWeek(),
            'converted_at' => now()->subDays(3),
        ]);
        Prospect::factory()->forProject($projet)->create([
            'next_action_at' => now()->subWeek(),
            'lost_at' => now()->subDays(2),
        ]);

        $this->actingAs($admin)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.en_retard', 1)
            ->assertJsonPath('summary.convertis', 1)
            ->assertJsonPath('summary.perdus', 1);
    }

    public function test_une_relance_a_venir_n_est_pas_en_retard(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->create(['next_action_at' => now()->addWeek()]);
        Prospect::factory()->forProject($projet)->create(['next_action_at' => null]);

        $this->actingAs($admin)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonPath('summary.en_retard', 0);
    }

    public function test_le_resume_compte_les_non_affectes(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->assignedTo($commercial)->count(2)->create();
        Prospect::factory()->forProject($projet)->unassigned()->count(4)->create();

        $this->actingAs($admin)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonPath('summary.total', 6)
            ->assertJsonPath('summary.non_affectes', 4);
    }

    public function test_le_resume_suit_le_perimetre_du_commercial(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $collegue = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->assignedTo($commercial)->count(2)->create();
        Prospect::factory()->forProject($projet)->assignedTo($collegue)->count(5)->create();
        Prospect::factory()->forProject($projet)->unassigned()->create();

        // Le résumé passe par la même requête que la liste : s'il échappait au
        // périmètre de rôle, il révélerait le volume d'activité des collègues.
        $this->actingAs($commercial)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.total', 3);
    }

    public function test_le_resume_ne_franchit_pas_la_frontiere_d_entreprise(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminA = User::factory()->adminClient()->create(['company_id' => $projetA->company_id]);

        Prospect::factory()->forProject($projetA)->count(2)->create();
        Prospect::factory()->forProject($projetB)->count(7)->create();

        $this->actingAs($adminA)->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonPath('summary.total', 2);
    }
}
