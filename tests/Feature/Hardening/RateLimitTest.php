<?php

namespace Tests\Feature\Hardening;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Limitation de débit de l'API.
 *
 * Sur un produit multi-entreprises, une boucle mal écrite chez un client
 * dégrade le service de tous les autres : la limitation cloisonne la ressource
 * au même titre que le scope cloisonne les données.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($project);

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();

        // Les compteurs survivent d'un test à l'autre dans le magasin de cache
        // partagé : sans purge, l'ordre d'exécution déciderait du résultat.
        RateLimiter::clear('u'.$this->admin->id);
    }

    public function test_les_operations_lourdes_ont_un_plafond_plus_bas(): void
    {
        $reponses = [];

        // Sept appels pour un plafond de six : le septième doit tomber.
        for ($i = 0; $i < 7; $i++) {
            $reponses[] = $this->actingAs($this->admin)
                ->getJson('/api/v1/prospects/export')
                ->getStatusCode();
        }

        $this->assertSame(429, end($reponses));
        $this->assertSame(200, $reponses[0]);
    }

    public function test_le_refus_porte_un_code_metier_exploitable(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->admin)->getJson('/api/v1/prospects/export');
        }

        // Un code stable permet au front de distinguer « trop vite » d'une
        // vraie erreur, et d'inviter à patienter plutôt qu'à réessayer.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/prospects/export')
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limit.exceeded');
    }

    public function test_le_plafond_est_par_utilisateur_et_non_par_adresse(): void
    {
        $collegue = User::factory()->adminClient()->forCompany($this->admin->company)->create();
        RateLimiter::clear('u'.$collegue->id);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->admin)->getJson('/api/v1/prospects/export');
        }

        $this->actingAs($this->admin)->getJson('/api/v1/prospects/export')->assertStatus(429);

        // Toute l'équipe partage souvent une sortie Internet unique : compter
        // par IP pénaliserait les collègues d'un utilisateur trop actif.
        $this->actingAs($collegue)->getJson('/api/v1/prospects/export')->assertOk();
    }

    public function test_les_lectures_courantes_ne_sont_pas_bridees_par_le_plafond_lourd(): void
    {
        // Vingt lectures : bien au-delà du plafond des opérations lourdes,
        // largement sous celui des lectures.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->admin)->getJson('/api/v1/prospects')->assertOk();
        }
    }
}
