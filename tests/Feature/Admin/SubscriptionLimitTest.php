<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant n°4 : les plafonds sont contrôlés côté serveur, à la création.
 */
class SubscriptionLimitTest extends TestCase
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
            ->for(Plan::factory()->state($plafonds))
            ->create();

        return $company;
    }

    public function test_la_creation_est_refusee_au_dela_du_plafond(): void
    {
        // Plan à 2 utilisateurs : l'admin, puis un second, puis refus.
        $company = $this->entreprise(['max_users' => 2]);
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/users', $this->donneesUtilisateur('premier@abc.mg'))
            ->assertCreated();

        $refus = $this->actingAs($admin)
            ->postJson('/api/v1/users', $this->donneesUtilisateur('second@abc.mg'));

        $refus->assertUnprocessable()
            // Code métier stable : le front s'en sert pour proposer une montée
            // de plan plutôt qu'afficher une erreur générique.
            ->assertJsonPath('code', 'subscription.limit_reached')
            ->assertJsonPath('meta.resource', 'users')
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.current', 2);

        $this->assertDatabaseMissing('users', ['email' => 'second@abc.mg']);
    }

    public function test_un_plafond_nul_vaut_illimite(): void
    {
        $company = $this->entreprise(['max_users' => null]);
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        foreach (range(1, 5) as $i) {
            $this->actingAs($admin)
                ->postJson('/api/v1/users', $this->donneesUtilisateur("user{$i}@abc.mg"))
                ->assertCreated();
        }

        $limits = app(SubscriptionLimitService::class);
        $this->assertNull($limits->limitFor($company, 'users'));
        $this->assertNull($limits->remaining($company, 'users'));
    }

    public function test_le_plafond_ne_compte_que_l_entreprise_concernee(): void
    {
        $entrepriseA = $this->entreprise(['max_users' => 2]);
        $entrepriseB = $this->entreprise(['max_users' => 2]);

        User::factory()->count(5)->forCompany($entrepriseB)->create();
        $admin = User::factory()->adminClient()->forCompany($entrepriseA)->create();

        // Les 5 utilisateurs de B ne doivent pas consommer le quota de A.
        $this->actingAs($admin)
            ->postJson('/api/v1/users', $this->donneesUtilisateur('a2@abc.mg'))
            ->assertCreated();
    }

    public function test_la_consommation_est_exposee_au_back_office(): void
    {
        $company = $this->entreprise(['max_users' => 5]);
        User::factory()->count(3)->forCompany($company)->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/admin/companies/{$company->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.usage.users.used', 3)
            ->assertJsonPath('data.usage.users.limit', 5)
            ->assertJsonPath('data.usage.users.remaining', 2);
    }

    public function test_une_retrogradation_sous_la_consommation_est_refusee(): void
    {
        $company = $this->entreprise(['max_users' => 10]);
        User::factory()->count(4)->forCompany($company)->create();

        $planEtroit = Plan::factory()->create(['max_users' => 2]);

        // Laisser passer produirait un état incohérent que le contrôle à la
        // création ne rattraperait jamais : il ne vise que les nouvelles
        // ressources, pas celles déjà en place.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson("/api/v1/admin/companies/{$company->id}/subscription", [
                'plan_id' => $planEtroit->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_une_montee_de_plan_est_acceptee(): void
    {
        $company = $this->entreprise(['max_users' => 2]);
        User::factory()->count(2)->forCompany($company)->create();

        $planLarge = Plan::factory()->business()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson("/api/v1/admin/companies/{$company->id}/subscription", [
                'plan_id' => $planLarge->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.plan.id', $planLarge->id);
    }

    /**
     * @return array<string, string>
     */
    private function donneesUtilisateur(string $email): array
    {
        return [
            'name' => 'Nouveau Commercial',
            'email' => $email,
            'password' => 'motdepasse-solide-123',
            'password_confirmation' => 'motdepasse-solide-123',
            'role' => 'commercial',
        ];
    }
}
