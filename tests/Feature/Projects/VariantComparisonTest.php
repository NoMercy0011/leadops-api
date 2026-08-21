<?php

namespace Tests\Feature\Projects;

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
 * Ce projet face aux autres de sa variante.
 *
 * La variante est l'axe de comparaison entre offres — c'est ce qui a justifié
 * d'en faire une table de référence plutôt qu'un champ libre. Les rapports
 * savaient déjà filtrer dessus, mais depuis un écran global ; la question posée
 * sur une fiche projet est plus directe : cette offre marche-t-elle mieux que
 * l'autre ?
 */
class VariantComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
    }

    private function projet(?Variant $variante = null): Project
    {
        $project = Project::factory()->forCompany($this->company)->create([
            'variant_id' => $variante?->id,
        ]);

        app(PipelineService::class)->createDefaultStages($project);

        return $project->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function comparaison(User $viewer, Project $projet): array
    {
        /** @var array<string, mixed> $donnees */
        $donnees = $this->actingAs($viewer)
            ->getJson("/api/v1/projects/{$projet->id}/variant-comparison")
            ->assertOk()
            ->json('data');

        return $donnees;
    }

    public function test_les_projets_de_la_meme_variante_sont_compares(): void
    {
        $variante = Variant::factory()->create(['company_id' => $this->company->id]);
        $admin = User::factory()->adminClient()->create(['company_id' => $this->company->id]);

        $courant = $this->projet($variante);
        $voisin = $this->projet($variante);
        $autreVariante = $this->projet(
            Variant::factory()->create(['company_id' => $this->company->id])
        );

        Prospect::factory()->forProject($courant)->count(2)->create();
        Prospect::factory()->forProject($voisin)->count(3)->create();
        Prospect::factory()->forProject($autreVariante)->count(9)->create();

        $comparaison = $this->comparaison($admin, $courant);

        $this->assertSame($variante->name, $comparaison['variant']);
        $this->assertCount(2, $comparaison['projets']);

        // Un projet d'une autre variante n'a rien à faire dans la comparaison :
        // c'est tout l'objet d'un axe d'agrégation.
        $noms = array_column($comparaison['projets'], 'project');
        $this->assertNotContains($autreVariante->name, $noms);
    }

    public function test_le_projet_courant_est_designe(): void
    {
        $variante = Variant::factory()->create(['company_id' => $this->company->id]);
        $admin = User::factory()->adminClient()->create(['company_id' => $this->company->id]);

        $courant = $this->projet($variante);
        $this->projet($variante);

        Prospect::factory()->forProject($courant)->count(2)->create();

        $comparaison = $this->comparaison($admin, $courant);

        // Désigné par l'API et non déduit du libellé : deux projets peuvent
        // porter des noms très proches.
        $marques = array_filter($comparaison['projets'], fn (array $l) => $l['courant'] === true);

        $this->assertCount(1, $marques);
        $this->assertSame($courant->id, reset($marques)['project_id']);
    }

    public function test_la_comparaison_porte_sur_toute_la_vie_du_projet(): void
    {
        $variante = Variant::factory()->create(['company_id' => $this->company->id]);
        $admin = User::factory()->adminClient()->create(['company_id' => $this->company->id]);

        $courant = $this->projet($variante);

        // Créés il y a six mois : la période par défaut des rapports est de
        // trente jours, et s'y tenir ferait ressortir toute offre ancienne à
        // zéro — donc favoriserait mécaniquement la plus récente.
        Prospect::factory()->forProject($courant)->count(4)->create([
            'created_at' => now()->subMonths(6),
        ]);

        $comparaison = $this->comparaison($admin, $courant);

        $this->assertSame(4, $comparaison['projets'][0]['total']);
    }

    public function test_un_projet_sans_variante_n_a_rien_a_comparer(): void
    {
        $admin = User::factory()->adminClient()->create(['company_id' => $this->company->id]);
        $sansVariante = $this->projet();

        $comparaison = $this->comparaison($admin, $sansVariante);

        // `null` et non une liste vide : l'écran doit pouvoir distinguer
        // « aucune variante » de « seule offre de sa variante ».
        $this->assertNull($comparaison['variant']);
        $this->assertSame([], $comparaison['projets']);
    }

    public function test_un_commercial_n_accede_pas_a_la_comparaison(): void
    {
        $variante = Variant::factory()->create(['company_id' => $this->company->id]);
        $projet = $this->projet($variante);
        $commercial = User::factory()->commercial()->create(['company_id' => $this->company->id]);
        $projet->members()->attach($commercial);

        $this->actingAs($commercial)
            ->getJson("/api/v1/projects/{$projet->id}/variant-comparison")
            ->assertForbidden();
    }

    public function test_une_autre_entreprise_n_atteint_pas_cette_comparaison(): void
    {
        $variante = Variant::factory()->create(['company_id' => $this->company->id]);
        $projet = $this->projet($variante);

        $autre = Company::factory()->create();
        Subscription::factory()->for($autre)->for(Plan::factory()->enterprise())->create();
        $adminAutre = User::factory()->adminClient()->create(['company_id' => $autre->id]);

        $this->actingAs($adminAutre)
            ->getJson("/api/v1/projects/{$projet->id}/variant-comparison")
            ->assertNotFound();
    }
}
