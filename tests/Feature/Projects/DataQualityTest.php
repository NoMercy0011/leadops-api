<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectAnswer;
use App\Models\Question;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use App\Services\ProspectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit de qualité des données d'un projet.
 *
 * La détection de doublons existait déjà, mais seulement à la création. Ces
 * trois mesures portent sur un fichier déjà constitué — le cas d'un import CSV,
 * qui est la première source de doublons.
 */
class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    private function projet(): Project
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($project);

        return $project->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function qualite(User $viewer, Project $projet): array
    {
        /** @var array<string, mixed> $donnees */
        $donnees = $this->actingAs($viewer)
            ->getJson("/api/v1/projects/{$projet->id}/data-quality")
            ->assertOk()
            ->json('data');

        return $donnees;
    }

    public function test_deux_ecritures_d_un_meme_numero_forment_un_doublon(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $service = app(ProspectService::class);

        // Le même numéro saisi une fois en international, une fois en local.
        // Comparer les valeurs brutes ne trouverait rien : c'est précisément ce
        // que les colonnes normalisées de la décision §10.1 rattrapent.
        $service->create($projet, ['first_name' => 'Marie', 'phone' => '+261 33 100 00 37']);
        $service->create($projet, ['first_name' => 'Aina', 'phone' => '0331000037']);
        $service->create($projet, ['first_name' => 'Paul', 'phone' => '0341234567']);

        $qualite = $this->qualite($admin, $projet);

        $this->assertSame(1, $qualite['doublons']['groupes']);
        $this->assertSame(2, $qualite['doublons']['prospects']);
        $this->assertSame('téléphone', $qualite['doublons']['exemples'][0]['type']);
    }

    public function test_le_meme_contact_dans_deux_projets_n_est_pas_un_doublon(): void
    {
        $projetA = $this->projet();
        $projetB = Project::factory()->forCompany($projetA->company)->create();
        app(PipelineService::class)->createDefaultStages($projetB);

        $admin = User::factory()->adminClient()->create(['company_id' => $projetA->company_id]);
        $service = app(ProspectService::class);

        // C'est la prémisse du multi-projets : une même personne peut être
        // travaillée sur deux offres. Le signaler serait un faux positif.
        $service->create($projetA, ['first_name' => 'Marie', 'phone' => '0331000037']);
        $service->create($projetB->fresh(), ['first_name' => 'Marie', 'phone' => '0331000037']);

        $this->assertSame(0, $this->qualite($admin, $projetA)['doublons']['groupes']);
    }

    public function test_les_prospects_sans_coordonnee_sont_comptes(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $service = app(ProspectService::class);

        $service->create($projet, ['first_name' => 'Fara']);
        $service->create($projet, ['first_name' => 'Koto', 'email' => 'koto@example.com']);
        $service->create($projet, ['first_name' => 'Tovo', 'phone' => '0341234567']);

        $qualite = $this->qualite($admin, $projet);

        // Seul le premier est injoignable : un email suffit à rendre joignable.
        $this->assertSame(1, $qualite['sans_coordonnee']['total']);
        $this->assertSame('Fara', $qualite['sans_coordonnee']['exemples'][0]['name']);
    }

    public function test_une_reponse_manquante_rend_la_qualification_incomplete(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        $obligatoire = Question::factory()->forProject($projet)->create(['required' => true]);
        $facultative = Question::factory()->forProject($projet)->create(['required' => false]);

        $complet = Prospect::factory()->forProject($projet)->create();
        $incomplet = Prospect::factory()->forProject($projet)->create();

        ProspectAnswer::query()->create([
            'company_id' => $projet->company_id,
            'prospect_id' => $complet->id,
            'question_id' => $obligatoire->id,
            'value' => 'répondu',
        ]);

        // L'incomplet n'a répondu qu'à la facultative : cela ne compense pas
        // l'obligatoire manquante.
        ProspectAnswer::query()->create([
            'company_id' => $projet->company_id,
            'prospect_id' => $incomplet->id,
            'question_id' => $facultative->id,
            'value' => 'répondu',
        ]);

        $qualite = $this->qualite($admin, $projet);

        $this->assertSame(1, $qualite['qualification_incomplete']['questions_obligatoires']);
        $this->assertSame(1, $qualite['qualification_incomplete']['total']);
    }

    public function test_un_projet_sans_question_obligatoire_n_a_rien_a_reprocher(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);

        Prospect::factory()->forProject($projet)->count(3)->create();

        $qualite = $this->qualite($admin, $projet);

        // Sans exigence, aucun dossier ne peut être incomplet — et surtout pas
        // les trois, ce que produirait un compte naïf de réponses manquantes.
        $this->assertSame(0, $qualite['qualification_incomplete']['total']);
    }

    public function test_un_commercial_n_accede_pas_a_l_audit(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $projet->members()->attach($commercial);

        $this->actingAs($commercial)
            ->getJson("/api/v1/projects/{$projet->id}/data-quality")
            ->assertForbidden();
    }

    public function test_une_autre_entreprise_n_atteint_pas_cet_audit(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminB = User::factory()->adminClient()->create(['company_id' => $projetB->company_id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/projects/{$projetA->id}/data-quality")
            ->assertNotFound();
    }
}
