<?php

namespace Tests\Feature\Projects;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant n°2 : le pipeline est une donnée, pas un enum. La sémantique
 * terminale passe par `is_won` / `is_lost`, jamais par le libellé.
 */
class PipelineStageTest extends TestCase
{
    use RefreshDatabase;

    private function projet(): Project
    {
        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($project);

        return $project->fresh();
    }

    private function admin(Project $project): User
    {
        return User::factory()->adminClient()->create(['company_id' => $project->company_id]);
    }

    public function test_le_reordonnancement_renumerote_toutes_les_etapes(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        $ids = $project->stages()->pluck('id')->all();
        $inverse = array_reverse($ids);

        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}/stages/reorder", ['stages' => $inverse])
            ->assertOk();

        $apres = $project->stages()->pluck('id')->all();

        $this->assertSame($inverse, $apres);
        $this->assertSame(
            range(1, count($ids)),
            $project->stages()->pluck('position')->all(),
            'Les positions doivent rester une suite continue à partir de 1.'
        );
    }

    public function test_un_reordonnancement_partiel_est_refuse(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        $ids = $project->stages()->pluck('id')->all();
        array_pop($ids);

        // Accepter un sous-ensemble laisserait des étapes sur une position
        // obsolète, donc un ordre d'affichage silencieusement faux.
        $this->actingAs($admin)
            ->patchJson("/api/v1/projects/{$project->id}/stages/reorder", ['stages' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stages');
    }

    public function test_une_seule_etape_gagnante_par_projet(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$project->id}/stages", [
                'name' => 'Signé', 'is_won' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_won');
    }

    public function test_une_etape_ne_peut_pas_etre_gagnante_et_perdue(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        // On libère d'abord les deux drapeaux pour isoler la règle testée.
        $project->stages()->where('is_won', true)->update(['is_won' => false]);
        $project->stages()->where('is_lost', true)->update(['is_lost' => false]);

        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$project->id}/stages", [
                'name' => 'Incohérente', 'is_won' => true, 'is_lost' => true,
            ])
            ->assertUnprocessable();
    }

    public function test_la_base_refuse_deux_etapes_gagnantes_meme_hors_application(): void
    {
        $project = $this->projet();

        // Contournement volontaire du contrôle applicatif : la contrainte doit
        // tenir en base, sans quoi deux écritures concurrentes passeraient.
        $this->expectException(QueryException::class);

        PipelineStage::query()->create([
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'name' => 'Doublon gagnant',
            'position' => 99,
            'is_won' => true,
        ]);
    }

    public function test_la_derniere_etape_ne_peut_pas_etre_supprimee(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        $ids = $project->stages()->pluck('id')->all();
        $derniere = array_pop($ids);

        foreach ($ids as $id) {
            $this->actingAs($admin)->deleteJson("/api/v1/stages/{$id}")->assertNoContent();
        }

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stages/{$derniere}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage');
    }

    public function test_une_couleur_hors_du_jeu_restreint_est_refusee(): void
    {
        $project = $this->projet();
        $admin = $this->admin($project);

        // Un sélecteur libre produirait des pipelines criards et des teintes
        // qui échouent au contraste sur l'un des deux thèmes.
        $this->actingAs($admin)
            ->postJson("/api/v1/projects/{$project->id}/stages", [
                'name' => 'Fluo', 'color' => '#00FF00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('color');
    }

    public function test_un_commercial_ne_modifie_pas_le_pipeline(): void
    {
        $project = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $project->company_id]);
        $project->members()->attach($commercial);

        // Il voit le projet, mais modifier le pipeline change la signification
        // des données de toute l'équipe : c'est une décision d'entreprise.
        $this->actingAs($commercial)->getJson("/api/v1/projects/{$project->id}")->assertOk();

        $this->actingAs($commercial)
            ->postJson("/api/v1/projects/{$project->id}/stages", ['name' => 'Perso'])
            ->assertForbidden();
    }

    public function test_le_pipeline_d_une_autre_entreprise_est_inatteignable(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminA = $this->admin($projetA);
        $etapeB = $projetB->stages()->first();

        $this->actingAs($adminA)
            ->patchJson("/api/v1/stages/{$etapeB->id}", ['name' => 'Piraté'])
            ->assertNotFound();
    }
}
