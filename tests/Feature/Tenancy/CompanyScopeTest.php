<?php

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le cloisonnement par entreprise est l'invariant n°1 de LeadOps. Ces tests
 * sont la preuve qu'il tient ; ils ne doivent jamais être désactivés.
 */
class CompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_entreprise_ne_voit_pas_les_utilisateurs_d_une_autre(): void
    {
        $entrepriseA = Company::factory()->create();
        $entrepriseB = Company::factory()->create();

        $adminA = User::factory()->adminClient()->forCompany($entrepriseA)->create();
        User::factory()->count(3)->forCompany($entrepriseA)->create();
        User::factory()->count(4)->forCompany($entrepriseB)->create();

        $this->actingAs($adminA);

        $visibles = User::query()->get();

        $this->assertCount(4, $visibles, 'admin + 3 commerciaux de A, et rien de B');
        $this->assertTrue(
            $visibles->every(fn (User $u) => $u->company_id === $entrepriseA->id)
        );
    }

    public function test_le_scope_survit_a_une_recherche_par_identifiant(): void
    {
        $entrepriseA = Company::factory()->create();
        $entrepriseB = Company::factory()->create();

        $adminA = User::factory()->adminClient()->forCompany($entrepriseA)->create();
        $cibleB = User::factory()->forCompany($entrepriseB)->create();

        $this->actingAs($adminA);

        // Connaître l'identifiant d'une ressource d'une autre entreprise ne
        // doit donner aucun accès : c'est le scénario d'énumération.
        $this->assertNull(User::query()->find($cibleB->id));
    }

    public function test_le_super_admin_voit_toutes_les_entreprises(): void
    {
        $entrepriseA = Company::factory()->create();
        $entrepriseB = Company::factory()->create();

        User::factory()->count(2)->forCompany($entrepriseA)->create();
        User::factory()->count(3)->forCompany($entrepriseB)->create();

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        // 2 + 3 + le super admin lui-même
        $this->assertCount(6, User::query()->get());
    }

    public function test_la_company_id_est_renseignee_automatiquement_a_la_creation(): void
    {
        $entreprise = Company::factory()->create();
        $admin = User::factory()->adminClient()->forCompany($entreprise)->create();

        $this->actingAs($admin);

        // Aucune company_id fournie : c'est l'observer du trait qui doit la
        // poser. Sans ce volet, le scope filtrerait les lectures tout en
        // laissant créer des lignes mal rattachées.
        $nouveau = new User([
            'name' => 'Sans entreprise explicite',
            'email' => 'nouveau@example.com',
            'password' => 'motdepasse',
            'role' => UserRole::Commercial,
        ]);
        $nouveau->save();

        $this->assertSame($entreprise->id, $nouveau->fresh()->company_id);
    }

    public function test_une_company_id_explicite_n_est_pas_ecrasee(): void
    {
        $entrepriseA = Company::factory()->create();
        $entrepriseB = Company::factory()->create();

        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);

        // Le back-office crée des utilisateurs pour le compte d'une entreprise
        // donnée : l'observer ne doit pas écraser une valeur fournie.
        $utilisateur = User::factory()->forCompany($entrepriseB)->create();

        $this->assertSame($entrepriseB->id, $utilisateur->company_id);
        $this->assertNotSame($entrepriseA->id, $utilisateur->company_id);
    }

    public function test_la_sortie_du_scope_est_explicite(): void
    {
        $entrepriseA = Company::factory()->create();
        $entrepriseB = Company::factory()->create();

        $adminA = User::factory()->adminClient()->forCompany($entrepriseA)->create();
        User::factory()->count(2)->forCompany($entrepriseB)->create();

        $this->actingAs($adminA);

        $this->assertCount(1, User::query()->get());
        $this->assertCount(3, User::query()->acrossAllCompanies()->get());
    }

    public function test_sans_utilisateur_authentifie_aucun_filtre_n_est_applique(): void
    {
        // Comportement assumé : seeders, commandes console et files d'attente
        // n'ont pas de contexte d'entreprise. C'est RouteProtectionTest qui
        // garantit qu'aucune requête HTTP n'atteint ce cas.
        Company::factory()->create();
        User::factory()->count(2)->create();

        $this->assertCount(2, User::query()->get());
    }
}
