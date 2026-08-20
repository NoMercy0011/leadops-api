<?php

namespace Tests\Feature\Prospects;

use App\Enums\ActivityType;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supprimer une étape occupée exige de désigner où vont ses prospects.
 *
 * Les déplacer d'office vers la première étape effacerait le travail de
 * qualification déjà fait : le choix revient à l'utilisateur.
 */
class StageDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
    }

    public function test_une_etape_occupee_ne_peut_pas_etre_supprimee_sans_repli(): void
    {
        $etape = $this->project->stages()->orderBy('position')->skip(2)->first();
        Prospect::factory()->forProject($this->project)->atStage($etape)->count(3)->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$etape->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fallback_stage_id');

        $this->assertDatabaseHas('pipeline_stages', ['id' => $etape->id]);
    }

    public function test_le_repli_deplace_les_prospects_et_journalise_chacun(): void
    {
        $etapes = $this->project->stages()->orderBy('position')->get();
        $aSupprimer = $etapes[2];
        $repli = $etapes[1];

        Prospect::factory()->forProject($this->project)->atStage($aSupprimer)->count(4)->create();
        ProspectActivity::query()->delete();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$aSupprimer->id}", ['fallback_stage_id' => $repli->id])
            ->assertNoContent();

        $this->assertDatabaseMissing('pipeline_stages', ['id' => $aSupprimer->id]);
        $this->assertSame(4, Prospect::query()->where('stage_id', $repli->id)->count());

        // Sans journal, l'historique d'un prospect montrerait un saut
        // inexpliqué et les durées de cycle porteraient sur une étape fantôme.
        $this->assertSame(
            4,
            ProspectActivity::query()
                ->acrossAllCompanies()
                ->where('type', ActivityType::StageChanged->value)
                ->count()
        );
    }

    public function test_le_repli_doit_appartenir_au_meme_projet(): void
    {
        $autreProjet = Project::factory()->forCompany($this->project->company)->create();
        app(PipelineService::class)->createDefaultStages($autreProjet);

        $etape = $this->project->stages()->orderBy('position')->skip(2)->first();
        Prospect::factory()->forProject($this->project)->atStage($etape)->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$etape->id}", [
                'fallback_stage_id' => $autreProjet->stages()->first()->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fallback_stage_id');
    }

    public function test_une_etape_vide_se_supprime_sans_repli(): void
    {
        $etape = $this->project->stages()->orderBy('position')->skip(3)->first();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/stages/{$etape->id}")
            ->assertNoContent();
    }
}
