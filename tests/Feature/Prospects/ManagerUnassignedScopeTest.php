<?php

namespace Tests\Feature\Prospects;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Variant;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prospects non affectés vus par un manager — lot 9, règle R6.
 *
 * Un prospect non affecté reste dans le périmètre de l'équipe : c'est celui
 * que le manager doit distribuer. Mais seulement dans **les projets de son
 * équipe**. Avant le lot 9, un manager voyait et pouvait archiver tous les
 * non-affectés de l'entreprise, et la comparaison par variante lui montrait
 * des projets où il n'avait personne.
 */
class ManagerUnassignedScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $manager;

    private Project $projetEquipe;

    private Project $projetHorsEquipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();

        $this->manager = User::factory()->manager()->forCompany($this->company)->create();
        $commercial = User::factory()->commercial()->forCompany($this->company)->managedBy($this->manager)->create();

        $variante = Variant::factory()->forCompany($this->company)->create();

        $this->projetEquipe = $this->projet($variante);
        $this->projetEquipe->members()->attach($commercial->id);

        $this->projetHorsEquipe = $this->projet($variante);
    }

    private function projet(Variant $variante): Project
    {
        $projet = Project::factory()->forCompany($this->company)->create(['variant_id' => $variante->id]);
        app(PipelineService::class)->createDefaultStages($projet);

        return $projet->fresh() ?? $projet;
    }

    public function test_le_manager_ne_voit_que_les_non_affectes_des_projets_de_son_equipe(): void
    {
        $visible = Prospect::factory()->forProject($this->projetEquipe)->unassigned()->create();
        $cache = Prospect::factory()->forProject($this->projetHorsEquipe)->unassigned()->create();

        /** @var list<array{id: int}> $liste */
        $liste = $this->actingAs($this->manager)->getJson('/api/v1/prospects')->assertOk()->json('data');
        $ids = collect($liste)->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($cache->id));
    }

    public function test_le_manager_ne_peut_pas_archiver_un_non_affecte_hors_de_son_equipe(): void
    {
        $cache = Prospect::factory()->forProject($this->projetHorsEquipe)->unassigned()->create();

        $this->actingAs($this->manager)
            ->patchJson("/api/v1/prospects/{$cache->id}/archive", ['archived' => true])
            ->assertForbidden();

        $this->assertFalse(Prospect::query()->acrossAllCompanies()->withArchived()->findOrFail($cache->id)->isArchived());
    }

    public function test_le_manager_archive_un_non_affecte_de_son_equipe(): void
    {
        $visible = Prospect::factory()->forProject($this->projetEquipe)->unassigned()->create();

        $this->actingAs($this->manager)
            ->patchJson("/api/v1/prospects/{$visible->id}/archive", ['archived' => true])
            ->assertOk();
    }

    public function test_la_comparaison_par_variante_ne_montre_au_manager_que_ses_projets(): void
    {
        Prospect::factory()->forProject($this->projetEquipe)->unassigned()->create();
        Prospect::factory()->forProject($this->projetHorsEquipe)->unassigned()->create();

        /** @var list<array{project_id: int}> $lignes */
        $lignes = $this->actingAs($this->manager)
            ->getJson("/api/v1/projects/{$this->projetEquipe->id}/variant-comparison")
            ->assertOk()
            ->json('data.projets');
        $projets = collect($lignes)->pluck('project_id');

        $this->assertTrue($projets->contains($this->projetEquipe->id));
        $this->assertFalse($projets->contains($this->projetHorsEquipe->id));
    }

    public function test_l_admin_client_voit_tous_les_non_affectes(): void
    {
        Prospect::factory()->forProject($this->projetEquipe)->unassigned()->create();
        Prospect::factory()->forProject($this->projetHorsEquipe)->unassigned()->create();
        $admin = User::factory()->adminClient()->forCompany($this->company)->create();

        $this->actingAs($admin)->getJson('/api/v1/prospects')->assertOk()->assertJsonCount(2, 'data');
    }
}
