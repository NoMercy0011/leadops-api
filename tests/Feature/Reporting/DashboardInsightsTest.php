<?php

namespace Tests\Feature\Reporting;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use App\Services\ProspectService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tendances, série, entonnoir, priorités du jour et classement — lot 12,
 * contrat `cadrage/contrats/LOT-12_Tableau-de-bord.md`.
 *
 * Les conversions et les pertes se lisent dans le journal, jamais dans
 * `converted_at`, que le retour en arrière efface. Les jours se découpent
 * dans le fuseau de l'entreprise.
 */
class DashboardInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 25/11/2026, 10:00 UTC = 13:00 à Antananarivo (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));

        $this->company = Company::factory()->create(['timezone' => 'Indian/Antananarivo']);
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->project = Project::factory()->forCompany($this->company)->create(['name' => 'Assurance Santé']);
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
    }

    private function etape(string $nom): PipelineStage
    {
        return $this->project->stages()->where('name', $nom)->firstOrFail();
    }

    private function deplacerLe(string $quandUtc, Prospect $prospect, string $etape): void
    {
        $this->travelTo(CarbonImmutable::parse($quandUtc, 'UTC'));
        $this->actingAs($this->admin);
        app(ProspectService::class)->moveToStage($prospect->fresh() ?? $prospect, $this->etape($etape));
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function tableau(User $qui, string $requete = '?period=7d'): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->actingAs($qui)->getJson('/api/v1/dashboard'.$requete)->assertOk()->json('data');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $tableau
     * @return array<string, array{converted: int, lost: int}>
     */
    private function parJour(array $tableau): array
    {
        $jours = [];
        foreach ($tableau['series'] as $point) {
            $jours[$point['date']] = ['converted' => $point['converted'], 'lost' => $point['lost']];
        }

        return $jours;
    }

    public function test_une_conversion_annulee_reste_comptee_au_jour_de_sa_conversion(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();
        $this->deplacerLe('2026-11-22 08:00:00', $prospect, 'Converti');
        $this->deplacerLe('2026-11-23 08:00:00', $prospect, 'Négociation');

        $tableau = $this->tableau($this->admin);

        $this->assertSame(1, $this->parJour($tableau)['2026-11-22']['converted']);
        $this->assertSame(1, $tableau['kpis']['converted']['value']);
    }

    public function test_les_jours_se_decoupent_dans_le_fuseau_de_l_entreprise(): void
    {
        [$soir, $nuit] = Prospect::factory()->forProject($this->project)->count(2)->create();
        // 20:30 UTC = 23:30 le 20/11 à Antananarivo ; 21:30 UTC = 00:30 le 21/11.
        $this->deplacerLe('2026-11-20 20:30:00', $soir, 'Converti');
        $this->deplacerLe('2026-11-20 21:30:00', $nuit, 'Perdu');

        $jours = $this->parJour($this->tableau($this->admin));

        $this->assertSame(['converted' => 1, 'lost' => 0], $jours['2026-11-20']);
        $this->assertSame(['converted' => 0, 'lost' => 1], $jours['2026-11-21']);
    }

    public function test_la_serie_compte_chaque_jour_de_la_periode_vides_compris(): void
    {
        $tableau = $this->tableau($this->admin);

        $this->assertCount(7, $tableau['series']);
        $this->assertSame('2026-11-19', $tableau['series'][0]['date']);
        $this->assertSame('2026-11-25', $tableau['series'][6]['date']);
        $this->assertSame(['key' => '7d', 'from' => '2026-11-19', 'to' => '2026-11-25', 'granularity' => 'day'], $tableau['period']);
    }

    public function test_le_trimestre_se_regroupe_par_semaine_du_lundi(): void
    {
        $tableau = $this->tableau($this->admin, '?period=90d');

        $this->assertSame('week', $tableau['period']['granularity']);
        foreach ($tableau['series'] as $point) {
            $this->assertSame(1, CarbonImmutable::parse($point['date'])->dayOfWeekIso, $point['date']);
        }
    }

    public function test_sans_periode_precedente_la_tendance_est_nulle(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();
        $this->deplacerLe('2026-11-24 08:00:00', $prospect, 'Converti');

        $kpi = $this->tableau($this->admin)['kpis']['converted'];

        $this->assertSame(['value' => 1, 'previous' => 0, 'trend' => null], $kpi);
    }

    public function test_la_tendance_compare_a_la_periode_precedente(): void
    {
        $prospects = Prospect::factory()->forProject($this->project)->count(3)->create();
        $this->deplacerLe('2026-11-15 08:00:00', $prospects[0], 'Converti');
        $this->deplacerLe('2026-11-20 08:00:00', $prospects[1], 'Converti');
        $this->deplacerLe('2026-11-21 08:00:00', $prospects[2], 'Converti');

        $kpi = $this->tableau($this->admin)['kpis']['converted'];

        $this->assertSame(2, $kpi['value']);
        $this->assertSame(1, $kpi['previous']);
        $this->assertEquals(100.0, $kpi['trend']);
    }

    public function test_l_entonnoir_compte_l_etape_la_plus_avancee_atteinte(): void
    {
        [$a, $b] = Prospect::factory()->forProject($this->project)->count(3)->create();
        $this->deplacerLe('2026-11-22 08:00:00', $a, 'Qualifié');
        // Saut direct : il a franchi toutes les étapes intermédiaires.
        $this->deplacerLe('2026-11-22 09:00:00', $b, 'Converti');
        $this->deplacerLe('2026-11-23 09:00:00', $b, 'Négociation');

        $entonnoir = $this->tableau($this->admin)['funnel'];
        $atteints = array_column($entonnoir['stages'], 'reached', 'name');

        $this->assertSame($this->project->id, $entonnoir['project']['id']);
        $this->assertSame(['Nouveau' => 3, 'Contacté' => 2, 'Qualifié' => 2, 'RDV' => 1, 'Négociation' => 1, 'Converti' => 1], $atteints);
        $this->assertNull($entonnoir['stages'][0]['pass_rate']);
        $this->assertEquals(66.7, $entonnoir['stages'][1]['pass_rate']);
    }

    public function test_une_entreprise_b_ne_voit_rien_de_a(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();
        $this->deplacerLe('2026-11-22 08:00:00', $prospect, 'Converti');

        $autre = Company::factory()->create(['timezone' => 'Indian/Antananarivo']);
        Subscription::factory()->for($autre)->for(Plan::factory()->enterprise())->create();
        $adminB = User::factory()->adminClient()->forCompany($autre)->create();

        $tableau = $this->tableau($adminB);

        $this->assertSame(0, $tableau['kpis']['converted']['value']);
        $this->assertSame(0, array_sum(array_column($tableau['series'], 'converted')));
        $this->assertNull($tableau['funnel']);
        $this->assertSame([], $tableau['leaderboard']);
    }

    public function test_un_commercial_ne_voit_que_ses_prospects_et_pas_de_classement(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();
        $this->project->members()->attach($commercial->id);
        $sien = Prospect::factory()->forProject($this->project)->assignedTo($commercial)->create();
        $autre = Prospect::factory()->forProject($this->project)->assignedTo($this->admin)->create();
        $this->deplacerLe('2026-11-22 08:00:00', $sien, 'Converti');
        $this->deplacerLe('2026-11-22 09:00:00', $autre, 'Converti');

        $tableau = $this->tableau($commercial);

        $this->assertSame(1, $tableau['kpis']['converted']['value']);
        $this->assertArrayNotHasKey('leaderboard', $tableau);
    }

    public function test_le_classement_attribue_la_conversion_au_commercial_affecte(): void
    {
        $malala = User::factory()->commercial()->forCompany($this->company)->create(['name' => 'Malala Rasoa']);
        $toky = User::factory()->commercial()->forCompany($this->company)->create(['name' => 'Toky Andria']);
        $prospects = Prospect::factory()->forProject($this->project)->assignedTo($malala)->count(2)->create();
        Prospect::factory()->forProject($this->project)->assignedTo($toky)->create();
        $this->deplacerLe('2026-11-22 08:00:00', $prospects[0], 'Converti');
        $this->deplacerLe('2026-11-24 08:00:00', $prospects[1], 'Converti');

        $classement = $this->tableau($this->admin)['leaderboard'];

        $this->assertSame('Malala Rasoa', $classement[0]['user']['name']);
        $this->assertSame(2, $classement[0]['converted']);
        $this->assertEquals(100.0, $classement[0]['conversion_rate']);
        $this->assertSame([0, 0, 0, 1, 0, 1, 0], $classement[0]['last_7_days']);
        $this->assertSame('Toky Andria', $classement[1]['user']['name']);
        $this->assertSame(0, $classement[1]['converted']);
    }

    public function test_une_periode_inconnue_est_refusee(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/dashboard?period=1y')
            ->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_les_champs_existants_sont_conserves(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonStructure(['data' => ['timezone', 'active_projects', 'prospects', 'conversion_rate', 'appointments', 'by_stage', 'by_project', 'period', 'kpis', 'series', 'funnel', 'today' => ['overdue', 'appointments', 'stale'], 'leaderboard']])
            ->assertJsonPath('data.period.key', '30d');
    }
}
