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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Archivage d'un projet, et absence de suppression.
 *
 * Un projet ne se supprime pas : ses prospects, leur journal d'activités et
 * leurs rendez-vous sont en `cascadeOnDelete`, et un seul appel les
 * emporterait sans retour possible. L'archivage remplace ce geste — il retire
 * le projet des listes sans rien détruire, et se défait.
 */
class ProjectArchiveTest extends TestCase
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

    public function test_aucune_route_ne_supprime_un_projet(): void
    {
        // La garantie porte sur la route et non sur un refus d'autorisation :
        // tant qu'aucun point d'entrée n'existe, aucune erreur d'appel ne peut
        // déclencher la cascade.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->values();

        $this->assertNotContains('api/v1/projects/{project}', $routes->all());
    }

    public function test_un_projet_s_archive_et_quitte_la_liste(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.status_label', 'Archivé')
            // Un projet archivé n'accepte plus de nouveaux prospects, comme un
            // projet suspendu ou terminé.
            ->assertJsonPath('data.accepts_new_prospects', false);

        $this->actingAs($admin)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_un_projet_archive_reste_accessible_et_retrouvable(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $projet->update(['status' => ProjectStatus::Archived]);

        // Sorti des listes, pas de la base : sa fiche répond toujours.
        $this->actingAs($admin)->getJson("/api/v1/projects/{$projet->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $projet->id);

        // Et le filtre explicite le fait réapparaître — sans quoi on rangerait
        // sans pouvoir rouvrir le tiroir.
        $this->actingAs($admin)->getJson('/api/v1/projects?status=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projet->id);
    }

    public function test_archiver_ne_touche_a_aucune_donnee(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->count(3)->create();

        // Relevé avant, comparé après : le nombre d'étapes du pipeline par
        // défaut n'a pas à être écrit ici, sans quoi le test casserait le jour
        // où ce pipeline change alors qu'il ne teste pas cela.
        $etapesAvant = $projet->stages()->count();

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => 'archived'])
            ->assertOk();

        $this->assertSame(3, $projet->prospects()->count());
        $this->assertSame($etapesAvant, $projet->stages()->count());
    }

    public function test_l_archivage_se_defait(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $projet->update(['status' => ProjectStatus::Archived]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.accepts_new_prospects', true);

        $this->actingAs($admin)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_un_projet_archive_refuse_les_nouveaux_prospects(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $projet->update(['status' => ProjectStatus::Archived]);

        // La fiche projet annonçait déjà « n'accepte plus de nouveaux
        // prospects » via `accepts_new_prospects`, mais rien ne l'appliquait :
        // l'API les acceptait. Le front revendiquait une règle inexistante.
        $this->actingAs($admin)
            ->postJson('/api/v1/prospects', [
                'project_id' => $projet->id,
                'first_name' => 'Zoé',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->assertSame(0, $projet->prospects()->count());
    }

    public function test_un_projet_reactive_les_accepte_de_nouveau(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        $projet->update(['status' => ProjectStatus::Archived]);
        $projet->update(['status' => ProjectStatus::Active]);

        $this->actingAs($admin)
            ->postJson('/api/v1/prospects', [
                'project_id' => $projet->id,
                'first_name' => 'Zoé',
            ])
            ->assertCreated();
    }

    public function test_un_commercial_ne_peut_pas_archiver(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $projet->members()->attach($commercial);

        $this->actingAs($commercial)
            ->patchJson("/api/v1/projects/{$projet->id}", ['status' => 'archived'])
            ->assertForbidden();
    }
}
