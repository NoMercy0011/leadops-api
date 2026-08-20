<?php

namespace Tests\Feature\Reporting;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Critère de sortie du lot 7 : le tableau de bord répond en moins de 500 ms sur
 * un jeu de 10 000 prospects.
 *
 * Le test mesure deux choses distinctes, et la seconde compte davantage :
 *
 * - **Le temps**, qui dépend de la machine et n'a donc qu'une valeur
 *   indicative — d'où une borne large plutôt qu'un seuil serré qui rendrait le
 *   test instable en intégration continue.
 * - **Le nombre de requêtes**, qui ne dépend d'aucun matériel. C'est lui qui
 *   attrape la vraie régression : un dashboard naïf émet une requête par
 *   projet et s'effondre au dixième, sans qu'aucun test de durée ne le voie sur
 *   un jeu de démonstration.
 *
 * Marqué `slow` : il insère 10 000 lignes et n'a pas sa place dans la boucle de
 * développement rapide. `php artisan test --exclude-group=slow` le saute.
 */
#[Group('slow')]
class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const VOLUME = 10_000;

    public function test_le_tableau_de_bord_tient_la_charge_sur_dix_mille_prospects(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $admin = User::factory()->adminClient()->forCompany($company)->create();

        // Cinq projets : c'est la répartition par projet qui révèle un N+1,
        // invisible avec un projet unique.
        $projets = collect(range(1, 5))->map(function () use ($company) {
            $projet = Project::factory()->forCompany($company)->create();
            app(PipelineService::class)->createDefaultStages($projet);

            return $projet->fresh();
        });

        $this->insererProspects($company->id, $projets);

        $this->assertSame(
            self::VOLUME,
            DB::table('prospects')->count(),
            'Le jeu de charge doit être réellement en base.'
        );

        $reporting = app(ReportingService::class);

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $depart = microtime(true);
        $tableau = $reporting->dashboard($admin);
        $duree = (microtime(true) - $depart) * 1000;

        // Les chiffres doivent être justes : un dashboard rapide mais faux ne
        // vaut rien.
        $this->assertSame(self::VOLUME, $tableau['prospects']['total']);
        $this->assertSame(5, $tableau['active_projects']);
        $this->assertCount(5, $tableau['by_project']);

        // Le nombre de requêtes ne doit pas croître avec le nombre de projets.
        // Vingt est large et laisse la place à une évolution raisonnable des
        // indicateurs, tout en refusant net un N+1 — qui produirait ici au
        // moins 5 requêtes supplémentaires, et des centaines en production.
        $this->assertLessThan(
            20,
            $requetes,
            "Le tableau de bord a émis {$requetes} requêtes : une répartition par projet doit passer par un GROUP BY unique, jamais par une requête par projet."
        );

        // Borne de temps volontairement large : la mesure dépend de la machine
        // et de la charge concurrente. C'est un garde-fou contre un
        // effondrement, pas une mesure de performance fine.
        $this->assertLessThan(
            2000,
            $duree,
            sprintf('Le tableau de bord a mis %.0f ms sur %d prospects.', $duree, self::VOLUME)
        );

        fwrite(STDERR, sprintf(
            "\n  [charge] %d prospects · %d requêtes · %.0f ms\n",
            self::VOLUME,
            $requetes,
            $duree,
        ));
    }

    /**
     * Insertion en masse par paquets.
     *
     * Passer par la factory produirait 10 000 requêtes et ferait durer le test
     * plusieurs minutes : ce qu'on veut mesurer ici est la lecture, pas
     * l'écriture.
     *
     * @param  Collection<int, Project>  $projets
     */
    private function insererProspects(int $companyId, $projets): void
    {
        $etapesParProjet = $projets->mapWithKeys(fn (Project $projet) => [
            $projet->id => PipelineStage::query()
                ->acrossAllCompanies()
                ->where('project_id', $projet->id)
                ->pluck('id')
                ->all(),
        ]);

        $maintenant = now()->toDateTimeString();
        $lignes = [];

        for ($i = 0; $i < self::VOLUME; $i++) {
            $projet = $projets[$i % $projets->count()];
            $etapes = $etapesParProjet[$projet->id];

            $lignes[] = [
                'company_id' => $companyId,
                'project_id' => $projet->id,
                'stage_id' => $etapes[$i % count($etapes)],
                'assigned_user_id' => null,
                'first_name' => "Prospect{$i}",
                'last_name' => 'Charge',
                // Un cinquième qualifié, un dixième converti : les agrégats
                // portent sur des données réparties, pas uniformes.
                'qualified_at' => $i % 5 === 0 ? $maintenant : null,
                'converted_at' => $i % 10 === 0 ? $maintenant : null,
                'lost_at' => $i % 7 === 0 ? $maintenant : null,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];

            if (count($lignes) === 1000) {
                DB::table('prospects')->insert($lignes);
                $lignes = [];
            }
        }

        if ($lignes !== []) {
            DB::table('prospects')->insert($lignes);
        }
    }
}
