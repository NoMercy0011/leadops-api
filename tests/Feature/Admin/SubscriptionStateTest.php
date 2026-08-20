<?php

namespace Tests\Feature\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un abonnement suspendu ou expiré bloque l'écriture, jamais la lecture.
 *
 * L'asymétrie est le cœur de la règle : couper la lecture reviendrait à
 * prendre les données du client en otage.
 */
class SubscriptionStateTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(string $etatAbonnement = 'active'): Company
    {
        $company = Company::factory()->create();

        $factory = Subscription::factory()->for($company)->for(Plan::factory()->enterprise());

        $factory = match ($etatAbonnement) {
            'suspended' => $factory->suspended(),
            'lapsed' => $factory->lapsed(),
            default => $factory,
        };

        $factory->create();

        return $company;
    }

    public function test_un_abonnement_suspendu_laisse_lire(): void
    {
        $company = $this->entreprise('suspended');
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
    }

    public function test_un_abonnement_suspendu_bloque_l_ecriture(): void
    {
        $company = $this->entreprise('suspended');
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'name' => 'Refusé',
                'email' => 'refuse@abc.mg',
                'password' => 'motdepasse-solide-123',
                'password_confirmation' => 'motdepasse-solide-123',
                'role' => 'commercial',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription.writes_blocked');

        $this->assertDatabaseMissing('users', ['email' => 'refuse@abc.mg']);
    }

    public function test_une_echeance_depassee_bloque_aussi_l_ecriture(): void
    {
        // Statut resté « active » en base, mais date d'expiration dépassée :
        // l'état effectif se calcule, il ne se lit pas seulement. Sans cela,
        // une entreprise écrirait librement jusqu'au passage de la tâche
        // planifiée qui met le statut à jour.
        $company = $this->entreprise('lapsed');
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->assertSame(SubscriptionStatus::Active, $company->subscription->status);
        $this->assertSame(SubscriptionStatus::Expired, $company->subscription->effectiveStatus());

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'name' => 'Refusé',
                'email' => 'expire@abc.mg',
                'password' => 'motdepasse-solide-123',
                'password_confirmation' => 'motdepasse-solide-123',
                'role' => 'commercial',
            ])
            ->assertForbidden();
    }

    public function test_la_suspension_d_une_entreprise_revoque_les_jetons(): void
    {
        $company = $this->entreprise();
        $utilisateur = User::factory()->adminClient()->forCompany($company)->create();
        $utilisateur->createToken('poste');

        $this->assertSame(1, $utilisateur->tokens()->count());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/companies/{$company->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        // Sans révocation, l'utilisateur déjà connecté continuerait de
        // travailler jusqu'à expiration de sa session.
        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_la_reactivation_retablit_l_ecriture(): void
    {
        $company = Company::factory()->suspended()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->assertFalse($company->allowsWrites());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/companies/{$company->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.allows_writes', true);
    }

    public function test_le_super_admin_n_est_jamais_bloque_par_un_abonnement(): void
    {
        $company = $this->entreprise('suspended');

        // C'est lui qui réactive les abonnements : le bloquer rendrait la
        // situation irréversible.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/companies/{$company->id}/activate")
            ->assertOk();
    }
}
