<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = collect([
            ['name' => 'Starter', 'slug' => 'starter', 'max_projects' => 2, 'max_users' => 5, 'max_prospects' => 1000, 'position' => 1],
            ['name' => 'Business', 'slug' => 'business', 'max_projects' => 10, 'max_users' => 25, 'max_prospects' => 10000, 'position' => 2],
            // NULL = illimité.
            ['name' => 'Enterprise', 'slug' => 'enterprise', 'max_projects' => null, 'max_users' => null, 'max_prospects' => null, 'position' => 3],
        ])->mapWithKeys(fn (array $attributs) => [
            $attributs['slug'] => Plan::query()->updateOrCreate(['slug' => $attributs['slug']], $attributs),
        ]);

        User::query()->updateOrCreate(
            ['email' => 'super@leadops.mg'],
            [
                'company_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::SuperAdmin,
                'email_verified_at' => now(),
            ]
        );

        // Deux entreprises, et non une seule : c'est le seul moyen de vérifier
        // le cloisonnement à la main pendant le développement.
        $this->creerEntreprise('ABC Commercial', 'abc', $plans['business'], 'abc.mg');
        $this->creerEntreprise('Delta Assurances', 'delta', $plans['starter'], 'delta.mg');
    }

    private function creerEntreprise(string $nom, string $slug, Plan $plan, string $domaine): void
    {
        $entreprise = Company::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $nom, 'timezone' => 'Indian/Antananarivo', 'default_country_code' => 'MG']
        );

        Subscription::query()->updateOrCreate(
            ['company_id' => $entreprise->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonths(2),
                'expires_at' => now()->addYear(),
            ]
        );

        $admin = $this->creerUtilisateur($entreprise->id, "Admin {$nom}", "admin@{$domaine}", UserRole::AdminClient);
        $manager = $this->creerUtilisateur($entreprise->id, "Manager {$nom}", "manager@{$domaine}", UserRole::Manager, $admin->id);

        $this->creerUtilisateur($entreprise->id, "Commercial 1 {$nom}", "commercial1@{$domaine}", UserRole::Commercial, $manager->id);
        $this->creerUtilisateur($entreprise->id, "Commercial 2 {$nom}", "commercial2@{$domaine}", UserRole::Commercial, $manager->id);
    }

    private function creerUtilisateur(int $companyId, string $nom, string $email, UserRole $role, ?int $managerId = null): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'company_id' => $companyId,
                'name' => $nom,
                'password' => Hash::make('password'),
                'role' => $role,
                'manager_id' => $managerId,
                'email_verified_at' => now(),
            ]
        );
    }
}
