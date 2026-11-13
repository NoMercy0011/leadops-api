<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int|null>  $plafonds
     */
    private function entreprise(array $plafonds = []): Company
    {
        $company = Company::factory()->create();

        Subscription::factory()
            ->for($company)
            ->for(Plan::factory()->enterprise()->state($plafonds))
            ->create();

        return $company;
    }

    public function test_un_projet_est_cree_avec_le_pipeline_par_defaut(): void
    {
        $company = $this->entreprise();
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/projects', [
            'name' => 'Assurance Santé',
            'product' => 'Assurance Santé Premium',
            'target' => 'Particuliers',
        ]);

        $reponse->assertCreated()
            ->assertJsonPath('data.name', 'Assurance Santé')
            // Un projet sans étape serait inutilisable : aucun prospect ne
            // pourrait y entrer, faute d'étape d'accueil.
            ->assertJsonCount(7, 'data.stages');

        $project = Project::query()->acrossAllCompanies()->firstWhere('name', 'Assurance Santé');

        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            $project->stages()->pluck('position')->all(),
            'Les positions doivent former une suite continue à partir de 1.'
        );

        // Une seule étape gagnante et une seule perdue : c'est par ces deux
        // drapeaux que passe la sémantique terminale, jamais par le libellé.
        $this->assertSame(1, $project->stages()->where('is_won', true)->count());
        $this->assertSame(1, $project->stages()->where('is_lost', true)->count());
    }

    public function test_deux_projets_ont_des_pipelines_independants(): void
    {
        // C'est le critère de sortie du lot : l'invariant n°2 tient si et
        // seulement si modifier un pipeline n'affecte pas l'autre projet.
        $company = $this->entreprise();
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $sante = $this->actingAs($admin)->postJson('/api/v1/projects', ['name' => 'Assurance Santé']);
        $credit = $this->actingAs($admin)->postJson('/api/v1/projects', ['name' => 'Crédit Immobilier']);

        $idSante = $sante->json('data.id');
        $idCredit = $credit->json('data.id');

        // Le pipeline de Santé perd une étape et en gagne une autre.
        $aSupprimer = PipelineStage::query()
            ->acrossAllCompanies()
            ->where('project_id', $idSante)
            ->where('name', 'Négociation')
            ->firstOrFail();

        $this->actingAs($admin)->deleteJson("/api/v1/stages/{$aSupprimer->id}")->assertNoContent();

        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$idSante}/stages", ['name' => 'Étude médicale', 'color' => 'violet'])
            ->assertCreated();

        $this->actingAs($admin)->getJson("/api/v1/projects/{$idSante}")
            ->assertOk()
            ->assertJsonCount(7, 'data.stages');

        // Crédit Immobilier n'a pas bougé.
        $this->actingAs($admin)->getJson("/api/v1/projects/{$idCredit}")
            ->assertOk()
            ->assertJsonCount(7, 'data.stages')
            ->assertJsonPath('data.stages.4.name', 'Négociation');

        $this->assertDatabaseMissing('pipeline_stages', [
            'project_id' => $idCredit,
            'name' => 'Étude médicale',
        ]);
    }

    public function test_les_variantes_distinguent_deux_projets(): void
    {
        $company = $this->entreprise();
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $particulier = Variant::factory()->forCompany($company)->create(['name' => 'Particulier']);
        $entreprise = Variant::factory()->forCompany($company)->create(['name' => 'Entreprise']);

        $this->actingAs($admin)->postJson('/api/v1/projects', [
            'name' => 'Santé Particuliers', 'variant_id' => $particulier->id,
        ])->assertCreated()->assertJsonPath('data.variant.name', 'Particulier');

        $this->actingAs($admin)->postJson('/api/v1/projects', [
            'name' => 'Santé Entreprises', 'variant_id' => $entreprise->id,
        ])->assertCreated()->assertJsonPath('data.variant.name', 'Entreprise');

        $this->actingAs($admin)
            ->getJson("/api/v1/projects?variant_id={$particulier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Santé Particuliers');
    }

    public function test_une_variante_d_une_autre_entreprise_est_refusee(): void
    {
        $entrepriseA = $this->entreprise();
        $entrepriseB = $this->entreprise();

        $admin = User::factory()->adminClient()->forCompany($entrepriseA)->create();
        $varianteB = Variant::factory()->forCompany($entrepriseB)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/projects', ['name' => 'Tentative', 'variant_id' => $varianteB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');
    }

    public function test_le_plafond_de_projets_est_applique(): void
    {
        $company = $this->entreprise(['max_projects' => 2]);
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/projects', ['name' => 'Un'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/projects', ['name' => 'Deux'])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/projects', ['name' => 'Trois'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'subscription.limit_reached')
            ->assertJsonPath('meta.resource', 'projects');
    }

    public function test_une_entreprise_ne_voit_pas_les_projets_d_une_autre(): void
    {
        $entrepriseA = $this->entreprise();
        $entrepriseB = $this->entreprise();

        $adminA = User::factory()->adminClient()->forCompany($entrepriseA)->create();
        Project::factory()->forCompany($entrepriseA)->count(2)->create();
        $projetB = Project::factory()->forCompany($entrepriseB)->create();

        $this->actingAs($adminA)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // 404 et non 403 : ne pas révéler l'existence de la ressource.
        $this->actingAs($adminA)->getJson("/api/v1/projects/{$projetB->id}")->assertNotFound();
    }

    public function test_un_commercial_ne_voit_que_ses_projets(): void
    {
        $company = $this->entreprise();
        $admin = User::factory()->adminClient()->forCompany($company)->create();
        $commercial = User::factory()->commercial()->forCompany($company)->create();

        $affecte = Project::factory()->forCompany($company)->create();
        $autre = Project::factory()->forCompany($company)->create();
        $affecte->members()->attach($commercial);

        $this->actingAs($commercial)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $affecte->id);

        $this->actingAs($commercial)->getJson("/api/v1/projects/{$autre->id}")->assertForbidden();
        $this->actingAs($commercial)->getJson("/api/v1/projects/{$affecte->id}")->assertOk();

        // Voir un projet ne donne pas le droit de le modifier.
        $this->actingAs($commercial)
            ->patchJson("/api/v1/projects/{$affecte->id}", ['name' => 'Renommé'])
            ->assertForbidden();
    }

    public function test_un_manager_voit_les_projets_de_son_equipe(): void
    {
        $company = $this->entreprise();
        $manager = User::factory()->manager()->forCompany($company)->create();
        $commercial = User::factory()->commercial()->managedBy($manager)->create();

        $projetEquipe = Project::factory()->forCompany($company)->create();
        $projetEquipe->members()->attach($commercial);

        Project::factory()->forCompany($company)->create();

        $this->actingAs($manager)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projetEquipe->id);
    }

    public function test_la_fiche_repond_comme_la_liste_pour_un_manager(): void
    {
        $company = $this->entreprise();
        $manager = User::factory()->manager()->forCompany($company)->create();
        $commercial = User::factory()->commercial()->managedBy($manager)->create();

        $projetEquipe = Project::factory()->forCompany($company)->create();
        $projetEquipe->members()->attach($commercial);

        $horsEquipe = Project::factory()->forCompany($company)->create();

        // La liste appliquait le périmètre d'équipe, la fiche exigeait une
        // adhésion nominative : le manager voyait trois projets dans /projets
        // et recevait un 403 en ouvrant chacun. Une liste et son détail ne
        // peuvent pas répondre différemment à la même question.
        $this->actingAs($manager)
            ->getJson("/api/v1/projects/{$projetEquipe->id}")
            ->assertOk();

        $this->actingAs($manager)
            ->getJson("/api/v1/projects/{$horsEquipe->id}")
            ->assertForbidden();
    }

    public function test_elargir_la_regle_du_manager_n_elargit_pas_celle_du_commercial(): void
    {
        $company = $this->entreprise();
        $manager = User::factory()->manager()->forCompany($company)->create();
        $lui = User::factory()->commercial()->managedBy($manager)->create();
        $son_collegue = User::factory()->commercial()->managedBy($manager)->create();

        $projetDuCollegue = Project::factory()->forCompany($company)->create();
        $projetDuCollegue->members()->attach($son_collegue);

        // Le périmètre d'un commercial reste lui seul : partager un manager ne
        // donne pas accès aux projets d'un collègue. C'est ce que garantit
        // `TeamResolver::scopeUserIds`, qui ne renvoie que lui pour ce rôle.
        $this->actingAs($lui)
            ->getJson("/api/v1/projects/{$projetDuCollegue->id}")
            ->assertForbidden();
    }
}
