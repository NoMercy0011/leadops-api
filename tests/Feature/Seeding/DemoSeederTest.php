<?php

namespace Tests\Feature\Seeding;

use App\Enums\ActivityType;
use App\Enums\ProjectActivityType;
use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Services\SubscriptionLimitService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le jeu de démonstration passe par les règles de l'application — lot 9,
 * faille F11.
 *
 * Un jeu d'essai qui contourne une règle montre un état que le produit ne
 * peut pas produire : le client le découvre en démonstration, et il a raison
 * de s'en étonner.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_aucune_entreprise_ne_depasse_le_plafond_de_projets_de_son_plan(): void
    {
        $limites = app(SubscriptionLimitService::class);

        foreach (Company::query()->get() as $entreprise) {
            $plafond = $limites->limitFor($entreprise, 'projects');

            if ($plafond !== null) {
                $this->assertLessThanOrEqual(
                    $plafond,
                    $limites->count($entreprise, 'projects'),
                    "{$entreprise->name} dépasse le plafond de projets de son plan",
                );
            }
        }
    }

    public function test_les_jalons_sont_dates_au_changement_d_etape_qui_les_produit(): void
    {
        $gagnantes = PipelineStage::query()->acrossAllCompanies()->where('is_won', true)->pluck('id')->all();
        $convertis = Prospect::query()->acrossAllCompanies()->withArchived()->whereNotNull('converted_at')->get();

        $this->assertNotEmpty($convertis, 'le jeu doit contenir des prospects convertis');

        foreach ($convertis as $prospect) {
            $passage = ProspectActivity::query()
                ->where('prospect_id', $prospect->id)
                ->where('type', ActivityType::StageChanged->value)
                ->get()
                ->filter(fn (ProspectActivity $a) => in_array($a->payload['to_id'] ?? null, $gagnantes, true))
                ->sortBy('occurred_at')
                ->last();

            $this->assertNotNull($passage, "le prospect {$prospect->id} est converti sans passage par une étape gagnée");
            $this->assertSame(
                $passage->occurred_at->toDateTimeString(),
                $prospect->converted_at?->toDateTimeString(),
                "la conversion du prospect {$prospect->id} doit dater de son passage en étape gagnée",
            );
        }
    }

    public function test_chaque_projet_a_sa_creation_au_journal(): void
    {
        foreach (Project::query()->acrossAllCompanies()->get() as $projet) {
            $this->assertTrue(
                ProjectActivity::query()->acrossAllCompanies()
                    ->where('project_id', $projet->id)
                    ->where('type', ProjectActivityType::ProjectCreated->value)
                    ->exists(),
                "le projet {$projet->name} n'a pas sa création au journal",
            );
        }
    }
}
