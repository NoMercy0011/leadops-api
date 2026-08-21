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
 * Archivage d'un prospect.
 *
 * Le cycle de vie d'un prospect tenait dans son étape, `converted_at` et
 * `lost_at`. Il manquait le dossier qu'on range sans qu'il soit perdu : un
 * contact injoignable depuis six mois, une ligne d'import inexploitable. Les
 * passer en « Perdu » ferait mentir le taux de perte, qui est un résultat
 * commercial et non un classement.
 *
 * Le point délicat est que l'exclusion doit valoir partout — listes, résumé,
 * tableau de bord, rapports, santé du pipeline, audit de qualité. C'est ce que
 * `NonArchivedScope` garantit, et ce que ces tests vérifient un écran après
 * l'autre.
 */
class ProspectArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->projet);
        $this->projet = $this->projet->fresh();

        $this->admin = User::factory()->adminClient()->create(['company_id' => $company->id]);
    }

    private function archiver(Prospect $prospect, bool $archive = true, ?string $motif = null): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", array_filter([
                'archived' => $archive,
                'motif' => $motif,
            ], fn ($valeur) => $valeur !== null))
            ->assertOk()
            ->assertJsonPath('data.is_archived', $archive);
    }

    /**
     * Lignes de santé du pipeline, une par étape.
     *
     * `json()` renvoie `mixed` : le passer directement à `collect()` laisse
     * ses paramètres de type irrésolus. La forme est connue du contrat d'API,
     * elle est donc déclarée ici une fois plutôt qu'à chaque appel.
     *
     * @return list<array<string, mixed>>
     */
    private function santeDuPipeline(): array
    {
        /** @var list<array<string, mixed>> $etapes */
        $etapes = $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->projet->id}/pipeline-health")
            ->assertOk()
            ->json('data.stages');

        return $etapes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalDe(Prospect $prospect): array
    {
        /** @var list<array<string, mixed>> $lignes */
        $lignes = $this->actingAs($this->admin)
            ->getJson("/api/v1/prospects/{$prospect->id}/activities")
            ->assertOk()
            ->json('data');

        return $lignes;
    }

    public function test_un_prospect_archive_quitte_la_liste_et_le_resume(): void
    {
        $prospects = Prospect::factory()->forProject($this->projet)->count(3)->create();

        $this->archiver($prospects[0]);

        $reponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Le résumé passe par la même requête : s'il comptait encore l'archivé,
        // la bande de chiffres contredirait le tableau juste en dessous.
        $reponse->assertJsonPath('summary.total', 2);
    }

    public function test_un_prospect_archive_reste_consultable(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();
        $this->archiver($prospect);

        // L'archivage range, il ne cache pas : la fiche répond toujours, sans
        // quoi on ne pourrait pas la rouvrir pour la désarchiver.
        $this->actingAs($this->admin)
            ->getJson("/api/v1/prospects/{$prospect->id}")
            ->assertOk()
            ->assertJsonPath('data.is_archived', true);
    }

    public function test_le_filtre_explicite_les_retrouve(): void
    {
        $prospects = Prospect::factory()->forProject($this->projet)->count(3)->create();
        $this->archiver($prospects[0]);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/prospects?archived=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $prospects[0]->id);
    }

    public function test_un_prospect_archive_sort_des_taux(): void
    {
        $etapes = $this->projet->stages;

        // Deux convertis sur quatre : 50 %. En archivant un non converti, le
        // dénominateur tombe à trois et le taux monte à 66,7 % — c'est bien ce
        // qu'on attend d'un dossier retiré du portefeuille.
        Prospect::factory()->forProject($this->projet)->count(2)->create([
            'converted_at' => now()->subDay(),
            'stage_id' => $etapes->firstWhere('is_won', true)->id,
        ]);
        $aRanger = Prospect::factory()->forProject($this->projet)->count(2)->create();

        $avant = $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->json('data');
        $this->assertSame(4, $avant['prospects']['total']);
        $this->assertEqualsWithDelta(50, $avant['conversion_rate'], 0.05);

        $this->archiver($aRanger[0]);

        $apres = $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->json('data');
        $this->assertSame(3, $apres['prospects']['total']);
        $this->assertEqualsWithDelta(66.7, $apres['conversion_rate'], 0.05);
    }

    public function test_un_prospect_archive_sort_de_la_sante_du_pipeline(): void
    {
        $etape = $this->projet->stages[1];
        $prospects = Prospect::factory()->forProject($this->projet)->count(2)->create([
            'stage_id' => $etape->id,
        ]);

        $this->archiver($prospects[0]);

        $ligne = collect($this->santeDuPipeline())->firstWhere('stage_id', $etape->id);
        $this->assertNotNull($ligne);

        // Un dossier rangé ne stationne pas dans une étape : le compter
        // gonflerait l'alerte d'immobilité sans qu'aucune action ne la réduise.
        $this->assertSame(1, $ligne['total']);
    }

    public function test_un_prospect_archive_sort_de_l_audit_de_qualite(): void
    {
        // Deux fois le même numéro : un doublon, tant que les deux sont actifs.
        Prospect::factory()->forProject($this->projet)->create(['phone_normalized' => '+261331000037']);
        $doublon = Prospect::factory()->forProject($this->projet)->create(['phone_normalized' => '+261331000037']);

        $avant = $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->projet->id}/data-quality")
            ->json('data.doublons.groupes');
        $this->assertSame(1, $avant);

        $this->archiver($doublon);

        $apres = $this->actingAs($this->admin)
            ->getJson("/api/v1/projects/{$this->projet->id}/data-quality")
            ->json('data.doublons.groupes');

        // Ranger l'un des deux résout le doublon : c'est même la façon la plus
        // simple de le traiter sans rien détruire.
        $this->assertSame(0, $apres);
    }

    public function test_l_archivage_se_defait_et_le_motif_est_journalise(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();

        $this->archiver($prospect, true, 'Injoignable depuis six mois.');

        $journal = $this->journalDe($prospect);

        $this->assertSame('archived', $journal[0]['type']);
        $this->assertSame('Injoignable depuis six mois.', $journal[0]['payload']['motif']);

        $this->archiver($prospect, false);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/prospects')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_un_commercial_ne_peut_pas_archiver(): void
    {
        $commercial = User::factory()->commercial()->create([
            'company_id' => $this->projet->company_id,
        ]);
        $prospect = Prospect::factory()->forProject($this->projet)->assignedTo($commercial)->create();

        // Sortir un dossier des statistiques est une décision d'encadrement :
        // un commercial qui pourrait archiver retirerait ses propres dossiers
        // en souffrance du périmètre de son manager.
        $this->actingAs($commercial)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertForbidden();
    }

    public function test_archiver_deux_fois_n_ecrit_qu_une_ligne_de_journal(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();

        $this->archiver($prospect);
        $this->archiver($prospect);

        // Un état déjà atteint n'est pas un événement : le réécrire noierait
        // l'historique réel sous des lignes sans information.
        $this->assertSame(
            1,
            collect($this->journalDe($prospect))->where('type', 'archived')->count(),
        );
    }
}
