<?php

namespace Tests\Feature\Hardening;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taille de page des listes de projets, d'utilisateurs et d'entreprises — lot
 * 11, règle R3.
 *
 * Ces listes avaient une taille fixe (25, 25, 20) et ignoraient `per_page` :
 * aucun risque, mais un comportement différent de celui des prospects. Elles
 * suivent désormais `PageSize`, plafond de 100 compris.
 */
class ListPageSizeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
    }

    public function test_les_projets_suivent_la_taille_de_page_bornee(): void
    {
        Project::factory()->forCompany($this->company)->count(3)->create();

        $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100)->assertJsonCount(3, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=2')
            ->assertOk()->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.last_page', 2);
        $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=0')
            ->assertOk()->assertJsonPath('meta.per_page', 25);
        // Le web redirige une page trop lointaine vers la dernière (C2) : il
        // compte sur une page vide accompagnée du vrai total (revue du lot 11).
        $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=2&page=9')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
    }

    public function test_les_utilisateurs_suivent_la_taille_de_page_bornee(): void
    {
        User::factory()->commercial()->forCompany($this->company)->count(2)->create();

        $this->actingAs($this->admin)->getJson('/api/v1/users?per_page=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100)->assertJsonCount(3, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/users?per_page=2')
            ->assertOk()->assertJsonPath('meta.last_page', 2);
        $this->actingAs($this->admin)->getJson('/api/v1/users?per_page=0')
            ->assertOk()->assertJsonPath('meta.per_page', 25);
    }

    public function test_les_entreprises_suivent_la_taille_de_page_bornee(): void
    {
        Company::factory()->count(2)->create();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->getJson('/api/v1/admin/companies?per_page=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100)->assertJsonCount(3, 'data');
        $this->actingAs($super)->getJson('/api/v1/admin/companies?per_page=0')
            ->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_une_autre_entreprise_reste_invisible_quelle_que_soit_la_taille(): void
    {
        $autre = Company::factory()->create();
        Project::factory()->forCompany($autre)->count(3)->create();
        User::factory()->commercial()->forCompany($autre)->count(3)->create();

        $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=100')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/users?per_page=100')
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
