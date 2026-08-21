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
 * Effectifs par valeur de filtre.
 *
 * Ce sont les nombres inscrits sur les jetons de la barre de filtres. La règle
 * qu'ils suivent n'est pas évidente et mérite d'être verrouillée : **chaque
 * famille ignore son propre filtre et respecte les autres**.
 */
class ProspectFacetsTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->projet);
        $this->projet = $this->projet->fresh();

        $this->admin = User::factory()->adminClient()->create(['company_id' => $company->id]);
        $this->commercial = User::factory()->commercial()->create(['company_id' => $company->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function facettes(string $requete = ''): array
    {
        /** @var array<string, mixed> $donnees */
        $donnees = $this->actingAs($this->admin)
            ->getJson("/api/v1/prospects{$requete}")
            ->assertOk()
            ->json('facets');

        return $donnees;
    }

    public function test_les_effectifs_sont_donnes_par_etape_et_par_commercial(): void
    {
        $premiere = $this->projet->stages[0];
        $deuxieme = $this->projet->stages[1];

        Prospect::factory()->forProject($this->projet)->count(3)->create([
            'stage_id' => $premiere->id,
            'assigned_user_id' => $this->commercial->id,
        ]);
        Prospect::factory()->forProject($this->projet)->count(2)->create([
            'stage_id' => $deuxieme->id,
            'assigned_user_id' => null,
        ]);

        $facettes = $this->facettes();

        $this->assertSame(3, $facettes['stages'][(string) $premiere->id]);
        $this->assertSame(2, $facettes['stages'][(string) $deuxieme->id]);

        $this->assertSame(3, $facettes['users'][(string) $this->commercial->id]);

        // Les non affectés se comptent sous la même clé que celle acceptée par
        // le filtre : un identifiant nul n'en a pas.
        $this->assertSame(2, $facettes['users']['none']);
    }

    public function test_une_famille_ignore_son_propre_filtre(): void
    {
        $premiere = $this->projet->stages[0];
        $deuxieme = $this->projet->stages[1];

        Prospect::factory()->forProject($this->projet)->count(3)->create(['stage_id' => $premiere->id]);
        Prospect::factory()->forProject($this->projet)->count(2)->create(['stage_id' => $deuxieme->id]);

        $facettes = $this->facettes("?stage_id={$premiere->id}");

        // Une fois « première étape » retenue, afficher zéro sur les autres
        // empêcherait de voir où basculer — or c'est précisément l'usage.
        $this->assertSame(3, $facettes['stages'][(string) $premiere->id]);
        $this->assertSame(2, $facettes['stages'][(string) $deuxieme->id]);
    }

    public function test_une_famille_respecte_les_filtres_des_autres(): void
    {
        $premiere = $this->projet->stages[0];
        $deuxieme = $this->projet->stages[1];

        Prospect::factory()->forProject($this->projet)->count(3)->create([
            'stage_id' => $premiere->id,
            'assigned_user_id' => $this->commercial->id,
        ]);
        Prospect::factory()->forProject($this->projet)->count(2)->create([
            'stage_id' => $deuxieme->id,
            'assigned_user_id' => null,
        ]);

        $facettes = $this->facettes("?assigned_user_id={$this->commercial->id}");

        // Les comptes par étape doivent annoncer ce que la liste montrera
        // vraiment, filtre commercial compris.
        $this->assertSame(3, $facettes['stages'][(string) $premiere->id]);
        $this->assertArrayNotHasKey((string) $deuxieme->id, $facettes['stages']);
    }

    public function test_les_prospects_archives_sont_ecartes_comme_de_la_liste(): void
    {
        $premiere = $this->projet->stages[0];

        $prospects = Prospect::factory()->forProject($this->projet)->count(3)->create([
            'stage_id' => $premiere->id,
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospects[0]->id}/archive", ['archived' => true])
            ->assertOk();

        // Le scope d'archivage s'applique à la même requête de base : un jeton
        // qui annoncerait trois pour une liste de deux serait un mensonge.
        $this->assertSame(2, $this->facettes()['stages'][(string) $premiere->id]);
    }

    public function test_un_commercial_ne_compte_que_son_perimetre(): void
    {
        $premiere = $this->projet->stages[0];
        $autre = User::factory()->commercial()->create([
            'company_id' => $this->projet->company_id,
        ]);

        Prospect::factory()->forProject($this->projet)->count(4)->create([
            'stage_id' => $premiere->id,
            'assigned_user_id' => $autre->id,
        ]);
        Prospect::factory()->forProject($this->projet)->count(2)->create([
            'stage_id' => $premiere->id,
            'assigned_user_id' => $this->commercial->id,
        ]);

        /** @var array<string, mixed> $facettes */
        $facettes = $this->actingAs($this->commercial)
            ->getJson('/api/v1/prospects')
            ->assertOk()
            ->json('facets');

        // `visibleTo` s'applique aussi aux comptes : un commercial qui verrait
        // « 6 » sur un jeton menant à deux lignes apprendrait au passage
        // combien de dossiers ses collègues détiennent.
        $this->assertSame(2, $facettes['stages'][(string) $premiere->id]);
        $this->assertArrayNotHasKey((string) $autre->id, $facettes['users']);
    }
}
