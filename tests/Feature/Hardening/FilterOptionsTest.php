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
 * Options des filtres — lot 10, règle R2.
 *
 * Les filtres projet et commercial lisaient la première page des listes,
 * bornée à 25 : au-delà, un projet ou un commercial devenait introuvable.
 * `GET /options` renvoie tout le périmètre, sans pagination, en ne gardant que
 * l'identifiant et le nom.
 */
class FilterOptionsTest extends TestCase
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

    public function test_au_dela_de_vingt_cinq_tout_le_perimetre_est_propose(): void
    {
        Project::factory()->forCompany($this->company)->count(30)->create();
        User::factory()->commercial()->forCompany($this->company)->count(30)->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/options')
            ->assertOk()
            ->assertJsonCount(30, 'data.projects')
            ->assertJsonCount(31, 'data.users')
            ->assertJsonStructure(['data' => ['projects' => [['id', 'name', 'status']], 'users' => [['id', 'name', 'role']]]]);
    }

    public function test_rien_d_une_autre_entreprise(): void
    {
        $autre = Company::factory()->create();
        Project::factory()->forCompany($autre)->create();
        User::factory()->commercial()->forCompany($autre)->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/options')
            ->assertOk()
            ->assertJsonCount(0, 'data.projects')
            ->assertJsonCount(1, 'data.users');
    }

    public function test_un_commercial_ne_voit_que_ses_projets(): void
    {
        $commercial = User::factory()->commercial()->forCompany($this->company)->create();
        $sien = Project::factory()->forCompany($this->company)->create();
        $sien->members()->attach($commercial->id);
        Project::factory()->forCompany($this->company)->create();

        $this->actingAs($commercial)
            ->getJson('/api/v1/options')
            ->assertOk()
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.id', $sien->id);
    }

    public function test_un_manager_ne_voit_que_son_equipe(): void
    {
        $manager = User::factory()->manager()->forCompany($this->company)->create();
        $sien = User::factory()->commercial()->forCompany($this->company)->managedBy($manager)->create();
        $autreManager = User::factory()->manager()->forCompany($this->company)->create();
        User::factory()->commercial()->forCompany($this->company)->managedBy($autreManager)->create();

        /** @var list<array{id: int}> $users */
        $users = $this->actingAs($manager)->getJson('/api/v1/options')->assertOk()->json('data.users');
        $ids = array_column($users, 'id');
        sort($ids);
        $attendus = [$manager->id, $sien->id];
        sort($attendus);

        $this->assertSame($attendus, $ids);
    }

    public function test_le_super_admin_est_refuse(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->getJson('/api/v1/options')->assertForbidden();
    }
}
