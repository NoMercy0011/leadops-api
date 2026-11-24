<?php

namespace Tests\Feature\Hardening;

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
 * Taille de page bornée — lot 10, règle R1.
 *
 * `per_page` n'avait pas de plafond : `?per_page=1000000` chargeait toute la
 * base visible, journal compris, sur un plan Enterprise illimité.
 */
class PageSizeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($company)->create();

        $projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($projet);
        Prospect::factory()->forProject($projet->fresh() ?? $projet)->count(105)->create();
    }

    public function test_une_taille_de_page_demesuree_est_ramenee_a_cent(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/prospects?per_page=1000000')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_une_taille_de_page_nulle_ou_negative_prend_la_valeur_par_defaut(): void
    {
        foreach (['0', '-5', 'abc'] as $valeur) {
            $this->actingAs($this->admin)
                ->getJson("/api/v1/prospects?per_page={$valeur}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', 25);
        }
    }
}
