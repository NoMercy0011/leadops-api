<?php

namespace Tests\Feature\Prospects;

use App\Enums\ActivityType;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkAssignAndImportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
    }

    public function test_la_reaffectation_en_masse_journalise_chaque_prospect(): void
    {
        $partant = User::factory()->commercial()->forCompany($this->project->company)->create();
        $repreneur = User::factory()->commercial()->forCompany($this->project->company)->create();

        $prospects = Prospect::factory()
            ->forProject($this->project)
            ->assignedTo($partant)
            ->count(25)
            ->create();

        ProspectActivity::query()->delete();

        $reponse = $this->actingAs($this->admin)->patchJson('/api/v1/prospects/bulk-assign', [
            'prospect_ids' => $prospects->pluck('id')->all(),
            'assigned_user_id' => $repreneur->id,
        ]);

        $reponse->assertOk()
            ->assertJsonPath('data.requested', 25)
            ->assertJsonPath('data.reassigned', 25);

        $this->assertSame(
            0,
            Prospect::query()->where('assigned_user_id', $partant->id)->count()
        );

        // Une ligne par prospect, jamais une seule pour le lot : l'historique
        // individuel deviendrait faux, et les KPIs de durée de cycle avec lui.
        $this->assertSame(
            25,
            ProspectActivity::query()
                ->acrossAllCompanies()
                ->where('type', ActivityType::Assigned->value)
                ->count()
        );
    }

    public function test_un_manager_ne_reaffecte_pas_hors_de_son_equipe(): void
    {
        $manager = User::factory()->manager()->forCompany($this->project->company)->create();
        $sien = User::factory()->commercial()->managedBy($manager)->create();
        $etranger = User::factory()->commercial()->forCompany($this->project->company)->create();

        $aLui = Prospect::factory()->forProject($this->project)->assignedTo($sien)->create();
        $horsPerimetre = Prospect::factory()->forProject($this->project)->assignedTo($etranger)->create();

        $reponse = $this->actingAs($manager)->patchJson('/api/v1/prospects/bulk-assign', [
            'prospect_ids' => [$aLui->id, $horsPerimetre->id],
            'assigned_user_id' => $sien->id,
        ]);

        // Glisser un identifiant hors périmètre dans la liste ne donne aucun
        // accès : l'autorisation s'évalue prospect par prospect.
        $reponse->assertOk()
            ->assertJsonPath('data.requested', 2)
            ->assertJsonPath('data.reassigned', 0)
            ->assertJsonPath('data.skipped', 2);

        $this->assertSame($etranger->id, $horsPerimetre->fresh()->assigned_user_id);
    }

    public function test_la_desaffectation_en_masse_est_possible(): void
    {
        $partant = User::factory()->commercial()->forCompany($this->project->company)->create();
        $prospects = Prospect::factory()->forProject($this->project)->assignedTo($partant)->count(3)->create();

        $this->actingAs($this->admin)->patchJson('/api/v1/prospects/bulk-assign', [
            'prospect_ids' => $prospects->pluck('id')->all(),
            'assigned_user_id' => null,
        ])->assertOk()->assertJsonPath('data.reassigned', 3);

        $this->assertSame(3, Prospect::query()->whereNull('assigned_user_id')->count());
    }

    public function test_l_import_csv_charge_les_lignes_valides_et_rapporte_les_autres(): void
    {
        $csv = <<<'CSV'
        prenom;nom;telephone;email;source
        Jean;Dupont;034 12 345 67;jean@example.com;Salon
        Marie;Rakoto;034 76 543 21;marie@example.com;Site web
        ;SansPrenom;034 11 111 11;;Salon
        Paul;Martin;034 22 222 22;adresse-invalide;Salon
        CSV;

        $fichier = UploadedFile::fake()->createWithContent('prospects.csv', $csv);

        $reponse = $this->actingAs($this->admin)->postJson('/api/v1/prospects/import', [
            'project_id' => $this->project->id,
            'file' => $fichier,
        ]);

        // 200 et non 422 : l'import a bien eu lieu, partiellement. Un 422
        // laisserait croire que rien n'a été enregistré.
        $reponse->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 2);

        $erreurs = $reponse->json('data.errors');
        $this->assertCount(2, $erreurs);
        // Les numéros de ligne correspondent à ce que l'utilisateur voit dans
        // son tableur : en-tête en ligne 1, données à partir de la 2.
        $this->assertSame([4, 5], array_column($erreurs, 'line'));

        $this->assertSame(2, Prospect::query()->count());
        $this->assertSame(
            '+261341234567',
            Prospect::query()->where('first_name', 'Jean')->value('phone_normalized')
        );
    }

    public function test_l_import_ignore_les_doublons_du_projet(): void
    {
        Prospect::factory()->forProject($this->project)->create([
            'first_name' => 'Jean',
            'phone' => '+261341234567',
            'phone_normalized' => '+261341234567',
            'email' => 'jean@example.com',
            'email_normalized' => 'jean@example.com',
        ]);

        $csv = "prenom;telephone\nJean;034 12 345 67\nMarie;034 99 999 99\n";
        $fichier = UploadedFile::fake()->createWithContent('doublons.csv', $csv);

        $this->actingAs($this->admin)->postJson('/api/v1/prospects/import', [
            'project_id' => $this->project->id,
            'file' => $fichier,
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_l_import_verifie_le_quota_sur_le_volume_total(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->state(['max_prospects' => 2]))->create();

        $projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($projet);
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $csv = "prenom\nUn\nDeux\nTrois\n";
        $fichier = UploadedFile::fake()->createWithContent('trop.csv', $csv);

        // Le quota est vérifié en amont de la boucle : le contrôler ligne par
        // ligne accepterait un import partiel, laissant l'utilisateur sans
        // moyen simple de savoir où reprendre.
        $this->actingAs($admin)->postJson('/api/v1/prospects/import', [
            'project_id' => $projet->id,
            'file' => $fichier,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'subscription.limit_reached');

        $this->assertSame(0, Prospect::query()->acrossAllCompanies()->count());
    }

    public function test_l_export_produit_un_csv_lisible_par_excel(): void
    {
        Prospect::factory()->forProject($this->project)->count(3)->create();

        $reponse = $this->actingAs($this->admin)
            ->get("/api/v1/prospects/export?project_id={$this->project->id}");

        $reponse->assertOk();
        $contenu = $reponse->streamedContent();

        // Sans BOM, Excel sous Windows affiche « Ã© » à la place des accents.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenu);
        $this->assertStringContainsString('Prénom;Nom', $contenu);
        $this->assertSame(4, substr_count(trim($contenu), "\n") + 1, 'En-tête plus trois lignes.');
    }

    public function test_un_commercial_ne_peut_pas_importer(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->project->company)->create();
        $fichier = UploadedFile::fake()->createWithContent('x.csv', "prenom\nJean\n");

        // L'import engage le quota d'abonnement de toute l'entreprise.
        $this->actingAs($commercial)->postJson('/api/v1/prospects/import', [
            'project_id' => $this->project->id,
            'file' => $fichier,
        ])->assertForbidden();
    }
}
