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
 * L'export suit la vue et les filtres de la liste — lot 9, faille F17.
 *
 * Avant, il ne lisait que `project_id` : dans la vue « Archivés », le bouton
 * Exporter renvoyait les actifs, et les archivés n'étaient jamais
 * exportables. L'export passe désormais par le même filtre que la liste.
 */
class ProspectExportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->projet);
        $this->projet = $this->projet->fresh() ?? $this->projet;
    }

    /**
     * Prénoms présents dans le CSV exporté, en-tête exclu.
     *
     * @return list<string>
     */
    private function prenomsExportes(string $parametres): array
    {
        $csv = $this->actingAs($this->admin)
            ->get('/api/v1/prospects/export'.$parametres)
            ->assertOk()
            ->streamedContent();

        $lignes = array_values(array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
        array_shift($lignes);

        return array_map(fn (string $ligne) => (string) str_getcsv($ligne, ';', '"', '')[0], $lignes);
    }

    public function test_l_export_de_la_vue_archives_ne_contient_que_les_archives(): void
    {
        Prospect::factory()->forProject($this->projet)->create(['first_name' => 'Actif']);
        $archive = Prospect::factory()->forProject($this->projet)->create(['first_name' => 'Range']);
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$archive->id}/archive", ['archived' => true])
            ->assertOk();

        $this->assertSame(['Range'], $this->prenomsExportes('?archived=1'));
        $this->assertSame(['Actif'], $this->prenomsExportes(''));
    }

    public function test_l_export_respecte_l_etape_et_la_recherche(): void
    {
        [$premiere, $seconde] = $this->projet->stages()->orderBy('position')->take(2)->get()->all();
        Prospect::factory()->forProject($this->projet)->atStage($premiere)->create(['first_name' => 'Hery']);
        Prospect::factory()->forProject($this->projet)->atStage($seconde)->create(['first_name' => 'Voahangy']);
        Prospect::factory()->forProject($this->projet)->atStage($seconde)->create(['first_name' => 'Tojo']);

        $this->assertSame(['Voahangy', 'Tojo'], $this->prenomsExportes("?stage_id={$seconde->id}"));
        $this->assertSame(['Tojo'], $this->prenomsExportes("?stage_id={$seconde->id}&search=Tojo"));
    }
}
