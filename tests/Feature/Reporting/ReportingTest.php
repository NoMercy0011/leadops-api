<?php

namespace Tests\Feature\Reporting;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Variant;
use App\Services\PipelineService;
use App\Services\ProspectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPIs du §2 et rapports du §11.
 *
 * Le fil conducteur : aucun libellé d'étape n'est testé. Les taux se calculent
 * sur des jalons horodatés — `qualified_at`, `converted_at`, `lost_at` — posés
 * à partir des drapeaux portés par l'étape.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['timezone' => 'Indian/Antananarivo']);
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
    }

    private function etape(string $drapeau): PipelineStage
    {
        return $this->project->stages()->where($drapeau, true)->firstOrFail();
    }

    private function deplacer(Prospect $prospect, PipelineStage $stage): void
    {
        $this->actingAs($this->admin);
        app(ProspectService::class)->moveToStage($prospect, $stage);
    }

    public function test_les_taux_reposent_sur_des_jalons_et_non_sur_des_libelles(): void
    {
        $prospects = Prospect::factory()->forProject($this->project)->count(10)->create();

        // Quatre qualifiés, dont deux convertis et un perdu.
        foreach ($prospects->take(4) as $prospect) {
            $this->deplacer($prospect, $this->etape('is_qualified'));
        }
        foreach ($prospects->take(2) as $prospect) {
            $this->deplacer($prospect->fresh(), $this->etape('is_won'));
        }
        $this->deplacer($prospects[3]->fresh(), $this->etape('is_lost'));

        // Les libellés sont maintenant renommés : rien ne doit changer.
        $this->project->stages()->where('is_won', true)->update(['name' => 'Signé']);
        $this->project->stages()->where('is_qualified', true)->update(['name' => 'Éligible']);

        $rapport = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports?from='.now()->subYear()->toDateString())
            ->assertOk();

        $rapport->assertJsonPath('data.totals.prospects', 10)
            ->assertJsonPath('data.totals.qualified', 4)
            ->assertJsonPath('data.totals.converted', 2)
            ->assertJsonPath('data.totals.lost', 1)
            // JSON n'a qu'un type numérique : 40.0 s'y encode « 40 ».
            // L'assertion porte donc sur l'entier, pas sur le flottant PHP.
            ->assertJsonPath('data.rates.qualification', 40)
            ->assertJsonPath('data.rates.conversion', 20);
    }

    public function test_la_qualification_ne_se_defait_pas(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();

        $this->deplacer($prospect, $this->etape('is_qualified'));
        $this->assertNotNull($prospect->fresh()->qualified_at);

        // Retour en arrière : la conversion se défait, la qualification non.
        // Un prospect qualifié puis perdu a bel et bien été qualifié, et
        // l'oublier ferait chuter le taux à mesure que les dossiers se closent.
        $this->deplacer($prospect->fresh(), $this->etape('is_lost'));

        $frais = $prospect->fresh();
        $this->assertNotNull($frais->qualified_at);
        $this->assertNotNull($frais->lost_at);
        $this->assertNull($frais->converted_at);
    }

    public function test_un_taux_sur_zero_prospect_vaut_null_et_non_zero(): void
    {
        $rapport = $this->actingAs($this->admin)->getJson('/api/v1/reports')->assertOk();

        // « Aucun prospect » n'est pas « 0 % de conversion » : afficher zéro
        // laisserait croire à un échec là où il n'y a rien à mesurer.
        $rapport->assertJsonPath('data.totals.prospects', 0)
            ->assertJsonPath('data.rates.conversion', null);
    }

    public function test_le_rapport_se_filtre_par_projet_et_par_variante(): void
    {
        $variante = Variant::factory()->forCompany($this->company)->create();
        $autreProjet = Project::factory()->forCompany($this->company)->create(['variant_id' => $variante->id]);
        app(PipelineService::class)->createDefaultStages($autreProjet);

        Prospect::factory()->forProject($this->project)->count(3)->create();
        Prospect::factory()->forProject($autreProjet->fresh())->count(5)->create();

        $depuis = now()->subYear()->toDateString();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/reports?from={$depuis}&project_id={$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.prospects', 3);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/reports?from={$depuis}&variant_id={$variante->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.prospects', 5);
    }

    public function test_les_prospects_non_affectes_forment_une_ligne_a_part(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();

        Prospect::factory()->forProject($this->project)->assignedTo($commercial)->count(2)->create();
        Prospect::factory()->forProject($this->project)->unassigned()->count(3)->create();

        $rapport = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports?from='.now()->subYear()->toDateString())
            ->assertOk();

        // Les écarter ferait mentir le total : 5 prospects, deux lignes,
        // 2 + 3 dans la répartition.
        $rapport->assertJsonCount(2, 'data.by_user')
            ->assertJsonFragment(['user' => 'Non affectés', 'total' => 3])
            ->assertJsonFragment(['user' => $commercial->name, 'total' => 2]);
    }

    public function test_le_tableau_de_bord_compte_les_rendez_vous_du_jour_en_heure_locale(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();

        // 22h00 UTC : 01h00 le lendemain à Antananarivo. Un filtre sur la date
        // UTC le rangerait la veille et le compteur du jour serait faux.
        $demainLocal = now('Indian/Antananarivo')->addDay()->startOfDay();
        Appointment::factory()->forProspect($prospect)
            ->at($demainLocal->copy()->addHour()->utc()->toDateTimeString())
            ->create();

        Appointment::factory()->forProspect($prospect)
            ->at(now('Indian/Antananarivo')->setTime(10, 0)->utc()->toDateTimeString())
            ->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appointments.today', 1)
            ->assertJsonPath('data.appointments.upcoming', 1);
    }

    public function test_le_cache_est_propre_a_chaque_utilisateur(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();

        Prospect::factory()->forProject($this->project)->assignedTo($commercial)->count(2)->create();
        Prospect::factory()->forProject($this->project)
            ->assignedTo(User::factory()->commercial()->forCompany($this->company)->create())
            ->count(5)
            ->create();

        // L'admin voit tout, le commercial ses deux plus les non affectés.
        // Une clé de cache par entreprise servirait la vue du premier arrivé au
        // suivant — une fuite de périmètre, pas un défaut d'affichage.
        $this->actingAs($this->admin)->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.prospects.total', 7);

        $this->actingAs($commercial)->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.prospects.total', 2);
    }

    public function test_le_cache_est_purge_par_un_changement_d_etape(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();

        $this->actingAs($this->admin)->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.prospects.converted', 0);

        $this->deplacer($prospect, $this->etape('is_won'));

        // Sans purge, les KPIs resteraient figés cinq minutes après la
        // conversion — soit le moment précis où on les regarde.
        $this->actingAs($this->admin)->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.prospects.converted', 1);
    }

    public function test_le_super_admin_n_accede_pas_aux_rapports_commerciaux(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_l_export_produit_un_csv_lisible_par_excel(): void
    {
        Prospect::factory()->forProject($this->project)->count(3)->create();

        $reponse = $this->actingAs($this->admin)
            ->get('/api/v1/reports/export?from='.now()->subYear()->toDateString());

        $reponse->assertOk();
        $contenu = $reponse->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        $this->assertStringContainsString('Taux de conversion', $contenu);
        $this->assertStringContainsString('Par commercial', $contenu);
    }
}
