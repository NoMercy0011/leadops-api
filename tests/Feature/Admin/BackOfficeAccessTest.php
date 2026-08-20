<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le back-office est le seul endroit du code qui sort du cloisonnement par
 * entreprise. C'est donc le seul où une erreur d'autorisation exposerait les
 * données de tous les clients d'un coup.
 */
class BackOfficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function entrepriseAvecAbonnement(): Company
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory())->create();

        return $company;
    }

    public function test_le_super_admin_liste_toutes_les_entreprises(): void
    {
        $this->entrepriseAvecAbonnement();
        $this->entrepriseAvecAbonnement();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/admin/companies')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesNonAutorises(): array
    {
        return [
            'admin client' => ['adminClient'],
            'manager' => ['manager'],
            'commercial' => ['commercial'],
        ];
    }

    #[DataProvider('rolesNonAutorises')]
    public function test_aucun_role_d_entreprise_n_atteint_le_back_office(string $etat): void
    {
        $company = $this->entrepriseAvecAbonnement();
        $utilisateur = User::factory()->{$etat}()->forCompany($company)->create();

        $this->actingAs($utilisateur)
            ->getJson('/api/v1/admin/companies')
            ->assertForbidden()
            ->assertJsonPath('code', 'auth.forbidden');
    }

    public function test_un_admin_client_ne_peut_pas_suspendre_une_entreprise(): void
    {
        $company = $this->entrepriseAvecAbonnement();
        $autre = $this->entrepriseAvecAbonnement();

        $this->actingAs(User::factory()->adminClient()->forCompany($company)->create())
            ->postJson("/api/v1/admin/companies/{$autre->id}/suspend")
            ->assertForbidden();

        $this->assertTrue($autre->fresh()->isActive());
    }

    public function test_le_back_office_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/admin/companies')->assertUnauthorized();
    }

    public function test_le_super_admin_cree_une_entreprise_et_son_abonnement(): void
    {
        $plan = Plan::factory()->create();

        $reponse = $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/admin/companies', [
                'name' => 'Nouvelle Entreprise',
                'slug' => 'nouvelle-entreprise',
                'timezone' => 'Indian/Antananarivo',
                'default_country_code' => 'MG',
                'plan_id' => $plan->id,
            ]);

        $reponse->assertCreated()
            ->assertJsonPath('data.name', 'Nouvelle Entreprise')
            ->assertJsonPath('data.subscription.plan.id', $plan->id)
            ->assertJsonPath('data.allows_writes', true);

        // L'entreprise et son abonnement forment un tout : une entreprise sans
        // plan serait incapable de rien créer.
        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $reponse->json('data.id'),
            'plan_id' => $plan->id,
        ]);
    }
}
