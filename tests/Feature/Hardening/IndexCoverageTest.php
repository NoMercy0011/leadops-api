<?php

namespace Tests\Feature\Hardening;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Revue des index sur un jeu de charge — §7, lot 8 du plan.
 *
 * Le test ne mesure pas un temps mais **interroge le plan d'exécution**. Une
 * durée dépend de la machine, du cache disque et de la charge concurrente ; un
 * balayage complet de table est un fait, indépendant de tout cela.
 *
 * C'est la différence qui compte à cette échelle : sur 50 000 lignes, une
 * requête sans index reste rapide en apparence — quelques dizaines de
 * millisecondes — et ne devient un problème qu'en production, sur un volume
 * qu'aucun test ne reproduit. Le plan d'exécution, lui, dit la vérité tout de
 * suite.
 */
#[Group('slow')]
class IndexCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const VOLUME = 50_000;

    /**
     * Le volume est réparti entre plusieurs entreprises, comme en production.
     *
     * Avec une seule entreprise, `company_id = ?` retient 100 % de la table :
     * le balayage complet devient alors le meilleur plan, et l'optimiseur de
     * MariaDB 11 le choisit à raison — 10.4 prenait encore l'index. Le test
     * accusait un index manquant qui ne manquait pas. L'entreprise interrogée
     * n'en est ici qu'une sur vingt-cinq.
     */
    private const ENTREPRISES = 25;

    private Company $company;

    private Project $project;

    /** @var list<array{company_id: int, project_id: int, etapes: list<int>}> */
    private array $locataires = [];

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->enterprise()->create();

        for ($n = 0; $n < self::ENTREPRISES; $n++) {
            $company = Company::factory()->create();
            Subscription::factory()->for($company)->for($plan)->create();

            $project = Project::factory()->forCompany($company)->create();
            app(PipelineService::class)->createDefaultStages($project);
            $project->refresh();

            if ($n === 0) {
                $this->company = $company;
                $this->project = $project;
            }

            $this->locataires[] = [
                'company_id' => $company->id,
                'project_id' => $project->id,
                'etapes' => $project->stages()->pluck('id')->all(),
            ];
        }

        $this->remplir();
    }

    public function test_les_requetes_du_produit_passent_par_un_index(): void
    {
        $etape = $this->project->stages()->first();
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();

        // Les axes de filtrage réellement employés par les écrans : liste des
        // prospects, kanban, agenda, reporting.
        $requetes = [
            'liste par entreprise' => DB::table('prospects')
                ->where('company_id', $this->company->id),

            'kanban : entreprise + projet + étape' => DB::table('prospects')
                ->where('company_id', $this->company->id)
                ->where('project_id', $this->project->id)
                ->where('stage_id', $etape->id),

            'périmètre commercial' => DB::table('prospects')
                ->where('company_id', $this->company->id)
                ->where('assigned_user_id', $commercial->id),

            'relances à venir' => DB::table('prospects')
                ->where('company_id', $this->company->id)
                ->whereNotNull('next_action_at'),

            'reporting : conversions' => DB::table('prospects')
                ->where('company_id', $this->company->id)
                ->whereNotNull('converted_at'),

            'rapprochement de doublons' => DB::table('prospects')
                ->where('company_id', $this->company->id)
                ->where('phone_normalized', '+261341234567'),
        ];

        $balayages = [];

        foreach ($requetes as $intitule => $requete) {
            $plan = $this->expliquer($requete->toSql(), $requete->getBindings());

            if ($plan['type'] === 'ALL') {
                $balayages[] = "{$intitule} (clé retenue : ".($plan['key'] ?? 'aucune').')';
            }
        }

        $this->assertSame(
            [],
            $balayages,
            'Ces requêtes balaient la table entière sur '.number_format(self::VOLUME, 0, ',', ' ')." lignes :\n- "
            .implode("\n- ", $balayages)
            ."\n\nÀ ce volume le balayage reste rapide et passe inaperçu ; il ne devient "
            .'un problème qu\'en production. Ajoutez l\'index correspondant.'
        );
    }

    public function test_le_volume_de_charge_est_bien_en_base(): void
    {
        $this->assertSame(self::VOLUME, DB::table('prospects')->count());
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{type: string, key: string|null, rows: int}
     */
    private function expliquer(string $sql, array $bindings): array
    {
        $ligne = DB::selectOne('EXPLAIN '.$sql, $bindings);

        return [
            'type' => $ligne->type ?? 'ALL',
            'key' => $ligne->key ?? null,
            'rows' => (int) ($ligne->rows ?? 0),
        ];
    }

    private function remplir(): void
    {
        $maintenant = now()->toDateTimeString();
        $lignes = [];

        for ($i = 0; $i < self::VOLUME; $i++) {
            $locataire = $this->locataires[$i % self::ENTREPRISES];
            // Rang de la ligne au sein de son entreprise : les proportions
            // ci-dessous valent ainsi pour chaque entreprise prise à part.
            $rang = intdiv($i, self::ENTREPRISES);
            $etapes = $locataire['etapes'];

            $lignes[] = [
                'company_id' => $locataire['company_id'],
                'project_id' => $locataire['project_id'],
                'stage_id' => $etapes[$rang % count($etapes)],
                'assigned_user_id' => null,
                'first_name' => "Charge{$i}",
                // Numéros distincts : un rapprochement testé sur une colonne
                // à valeur unique ne dirait rien de sa sélectivité réelle.
                'phone_normalized' => '+2613'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'next_action_at' => $rang % 20 === 0 ? $maintenant : null,
                'converted_at' => $rang % 10 === 0 ? $maintenant : null,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];

            if (count($lignes) === 2000) {
                DB::table('prospects')->insert($lignes);
                $lignes = [];
            }
        }

        if ($lignes !== []) {
            DB::table('prospects')->insert($lignes);
        }

        // Sans statistiques à jour, l'optimiseur raisonne sur une table qu'il
        // croit vide et choisit un balayage : le test mesurerait alors son
        // ignorance, pas la qualité des index.
        DB::statement('ANALYZE TABLE prospects');
    }
}
