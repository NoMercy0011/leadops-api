<?php

namespace Tests\Feature\Subscription;

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
 * Abonnement et consommation vus par l'entreprise elle-même.
 *
 * L'invariant n°4 veut que les plafonds soient tenus par le serveur. Ils
 * l'étaient déjà, mais le client n'avait aucun moyen de les voir approcher :
 * le premier signe du plafond était un refus de création en pleine saisie.
 */
class SubscriptionOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(?Plan $plan = null): Company
    {
        $company = Company::factory()->create();

        Subscription::factory()
            ->for($company)
            ->for($plan ?? Plan::factory()->create())
            ->create();

        return $company;
    }

    public function test_un_admin_client_voit_son_plan_et_sa_consommation(): void
    {
        $company = $this->entreprise();
        $admin = User::factory()->adminClient()->create(['company_id' => $company->id]);

        // Deux projets, trois prospects, deux utilisateurs (l'admin et un
        // commercial) : de quoi vérifier que chaque compteur compte sa propre
        // ressource et pas celle d'à côté.
        $projets = Project::factory()->forCompany($company)->count(2)->create();
        app(PipelineService::class)->createDefaultStages($projets[0]);
        Prospect::factory()->forProject($projets[0]->fresh())->count(3)->create();
        User::factory()->commercial()->create(['company_id' => $company->id]);

        $donnees = $this->actingAs($admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->json('data');

        $this->assertSame('Starter', $donnees['subscription']['plan']['name']);

        $this->assertSame(2, $donnees['usage']['projects']['used']);
        $this->assertSame(2, $donnees['usage']['projects']['limit']);
        $this->assertSame(0, $donnees['usage']['projects']['remaining']);

        $this->assertSame(2, $donnees['usage']['users']['used']);
        $this->assertSame(3, $donnees['usage']['prospects']['used']);
    }

    public function test_un_plan_illimite_annonce_null_et_non_un_grand_nombre(): void
    {
        $company = $this->entreprise(Plan::factory()->enterprise()->create());
        $admin = User::factory()->adminClient()->create(['company_id' => $company->id]);

        $usage = $this->actingAs($admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->json('data.usage');

        // `null` et non une sentinelle : l'écran doit pouvoir afficher
        // « illimité » plutôt qu'une jauge, qui n'aurait aucun sens.
        $this->assertNull($usage['projects']['limit']);
        $this->assertNull($usage['projects']['remaining']);
        $this->assertSame(0, $usage['projects']['used']);
    }

    public function test_la_consommation_ne_compte_que_sa_propre_entreprise(): void
    {
        $mienne = $this->entreprise();
        $autre = $this->entreprise();

        Project::factory()->forCompany($mienne)->create();
        Project::factory()->forCompany($autre)->count(2)->create();

        $admin = User::factory()->adminClient()->create(['company_id' => $mienne->id]);

        $usage = $this->actingAs($admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->json('data.usage');

        // Le service compte hors cloisonnement, par `company_id` explicite :
        // un compteur qui déborderait ferait facturer à une entreprise
        // l'activité d'une autre.
        $this->assertSame(1, $usage['projects']['used']);
    }

    public function test_l_ecran_repond_encore_quand_l_abonnement_est_suspendu(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->create())->suspended()->create();

        $admin = User::factory()->adminClient()->create(['company_id' => $company->id]);

        // C'est précisément là qu'il sert : bloquer aussi cette lecture
        // laisserait l'Admin Client devant des refus d'écriture sans pouvoir
        // en consulter la raison.
        $this->actingAs($admin)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.effective_status', 'suspended');
    }

    public function test_ni_le_manager_ni_le_commercial_n_y_ont_acces(): void
    {
        $company = $this->entreprise();

        foreach (['manager', 'commercial'] as $role) {
            $utilisateur = User::factory()->{$role}()->create(['company_id' => $company->id]);

            // Le plan et l'échéance sont une affaire de gestion : ni l'un ni
            // l'autre n'a de décision à prendre à partir de ces chiffres.
            $this->actingAs($utilisateur)
                ->getJson('/api/v1/subscription')
                ->assertForbidden();
        }
    }

    public function test_un_super_admin_est_renvoye_vers_sa_propre_route(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // Il n'a pas d'entreprise : la déduire de l'utilisateur n'a pas de
        // sens pour lui, et `/admin/companies/{company}/usage` la prend en
        // paramètre.
        $this->actingAs($superAdmin)
            ->getJson('/api/v1/subscription')
            ->assertForbidden();
    }
}
