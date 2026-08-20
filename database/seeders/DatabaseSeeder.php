<?php

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityLogger;
use App\Services\PipelineService;
use App\Services\ProspectService;
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

        $c1 = $this->creerUtilisateur($entreprise->id, "Commercial 1 {$nom}", "commercial1@{$domaine}", UserRole::Commercial, $manager->id);
        $c2 = $this->creerUtilisateur($entreprise->id, "Commercial 2 {$nom}", "commercial2@{$domaine}", UserRole::Commercial, $manager->id);

        $this->creerProjets($entreprise, [$c1, $c2]);
    }

    /**
     * Deux projets par entreprise, de variantes différentes.
     *
     * C'est le minimum pour vérifier à la main que les pipelines sont bien
     * indépendants : avec un seul projet, l'invariant n°2 ne se voit pas.
     *
     * @param  list<User>  $commerciaux
     */
    private function creerProjets(Company $entreprise, array $commerciaux): void
    {
        $variantes = collect(['Particulier', 'Entreprise'])
            ->mapWithKeys(fn (string $nom, int $index) => [
                $nom => Variant::query()->updateOrCreate(
                    ['company_id' => $entreprise->id, 'name' => $nom],
                    ['position' => $index + 1]
                ),
            ]);

        $catalogue = [
            ['Assurance Santé', 'Assurance Santé Premium', 'Particuliers', 'Particulier'],
            ['Crédit Immobilier', 'Prêt à taux fixe', 'Ménages primo-accédants', 'Entreprise'],
        ];

        foreach ($catalogue as [$nomProjet, $produit, $cible, $variante]) {
            $existe = Project::query()
                ->acrossAllCompanies()
                ->where('company_id', $entreprise->id)
                ->where('name', $nomProjet)
                ->exists();

            if ($existe) {
                continue;
            }

            $projet = Project::query()->create([
                'company_id' => $entreprise->id,
                'variant_id' => $variantes[$variante]->id,
                'name' => $nomProjet,
                'product' => $produit,
                'target' => $cible,
                'status' => ProjectStatus::Active,
            ]);

            app(PipelineService::class)->createDefaultStages($projet);

            $projet->members()->sync(collect($commerciaux)->pluck('id')->all());

            $this->creerProspects($projet->fresh(), $commerciaux);
        }
    }

    /**
     * Prospects répartis sur le pipeline, dont quelques-uns non affectés.
     *
     * L'objectif n'est pas le volume mais la variété : un kanban dont toutes
     * les colonnes sont vides sauf la première ne permet pas de juger l'écran,
     * et sans prospect libre on ne peut pas essayer la distribution.
     *
     * @param  list<User>  $commerciaux
     */
    private function creerProspects(Project $projet, array $commerciaux): void
    {
        if (Prospect::query()->acrossAllCompanies()->where('project_id', $projet->id)->exists()) {
            return;
        }

        $etapes = $projet->stages()->orderBy('position')->get();
        $service = app(ProspectService::class);

        $noms = [
            ['Jean', 'Dupont'], ['Marie', 'Rakoto'], ['Paul', 'Martin'],
            ['Sarah', 'Randria'], ['Luc', 'Bernard'], ['Hery', 'Rasoa'],
            ['Nadia', 'Andria'], ['Eric', 'Petit'], ['Fara', 'Rabe'],
            ['Tovo', 'Rakotoson'], ['Aina', 'Ravelo'], ['Koto', 'Rajao'],
        ];

        foreach ($noms as $index => [$prenom, $nom]) {
            $etape = $etapes[$index % max(1, $etapes->count() - 2)];

            // Un prospect sur quatre reste libre, à distribuer.
            $commercial = $index % 4 === 3
                ? null
                : $commerciaux[$index % count($commerciaux)];

            $prospect = $service->create($projet, [
                'first_name' => $prenom,
                'last_name' => $nom,
                'phone' => '+2613'.(2 + $index % 3).str_pad((string) (1000000 + $index * 37), 7, '0', STR_PAD_LEFT),
                'email' => mb_strtolower($prenom.'.'.$nom).'@example.com',
                'source' => ['Salon', 'Site web', 'Recommandation', 'Appel entrant'][$index % 4],
                'assigned_user_id' => $commercial?->id,
                'stage_id' => $etape->id,
            ]);

            if ($index % 3 === 0) {
                app(ActivityLogger::class)->noteAdded($prospect, 'Premier contact établi, à recontacter.');
            }
        }
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
