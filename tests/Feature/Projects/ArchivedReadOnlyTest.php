<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Un projet ou un prospect archivé est en lecture seule — lot 9, règle R3.
 *
 * Sans cette règle, une conversion enregistrée sur un prospect archivé
 * n'entrait dans aucun taux, et un pipeline modifié sur un projet archivé
 * réécrivait l'historique d'une campagne close. Seuls la réactivation (ou le
 * désarchivage) et, pour un prospect, l'effacement restent possibles.
 */
class ArchivedReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();

        $this->projet = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($this->projet);
        $this->projet = $this->projet->fresh() ?? $this->projet;
    }

    /**
     * @param  TestResponse<Response>  $reponse
     */
    private function assertLectureSeule(TestResponse $reponse): void
    {
        $reponse->assertStatus(422)->assertJsonPath('code', 'archive.read_only');
    }

    private function archiverLeProjet(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->projet->id}", ['status' => ProjectStatus::Archived->value])
            ->assertOk();
    }

    private function prospectArchive(): Prospect
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertOk();

        return $prospect;
    }

    public function test_un_projet_archive_refuse_la_modification_de_ses_champs(): void
    {
        $this->archiverLeProjet();

        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->projet->id}", ['name' => 'Nouveau nom']));

        $this->assertNotSame('Nouveau nom', $this->projet->fresh()?->name);
    }

    public function test_un_projet_archive_refuse_les_modifications_de_pipeline_de_questionnaire_et_d_equipe(): void
    {
        $etape = $this->projet->stages()->firstOrFail();
        $this->archiverLeProjet();

        $this->assertLectureSeule($this->actingAs($this->admin)
            ->postJson("/api/v1/projects/{$this->projet->id}/stages", ['name' => 'Relance']));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/stages/{$etape->id}", ['name' => 'Renommée']));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->postJson("/api/v1/projects/{$this->projet->id}/questions", ['type' => 'text', 'label' => 'Budget ?']));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->putJson("/api/v1/projects/{$this->projet->id}/members", ['user_ids' => []]));
    }

    public function test_un_projet_archive_se_reactive(): void
    {
        $this->archiverLeProjet();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->projet->id}", ['status' => ProjectStatus::Active->value])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/projects/{$this->projet->id}", ['name' => 'Nouveau nom'])
            ->assertOk();
    }

    public function test_un_prospect_d_un_projet_archive_est_en_lecture_seule(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();
        $this->archiverLeProjet();

        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}", ['first_name' => 'Autre']));
    }

    public function test_un_prospect_archive_refuse_toute_ecriture(): void
    {
        $prospect = $this->prospectArchive();
        $etape = $this->projet->stages()->orderByDesc('position')->firstOrFail();

        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}", ['first_name' => 'Autre']));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $etape->id]));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/notes", ['note' => 'Rappeler lundi']));
        $this->assertLectureSeule($this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/assign", ['assigned_user_id' => $this->admin->id]));

        $this->assertNotSame('Autre', Prospect::query()->withArchived()->find($prospect->id)?->first_name);
    }

    public function test_un_prospect_archive_se_desarchive(): void
    {
        $prospect = $this->prospectArchive();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.is_archived', false);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}", ['first_name' => 'Autre'])
            ->assertOk();
    }

    public function test_une_autre_entreprise_ne_peut_pas_desarchiver(): void
    {
        $prospect = $this->prospectArchive();

        $autre = Company::factory()->create();
        Subscription::factory()->for($autre)->for(Plan::factory()->enterprise())->create();
        $adminB = User::factory()->adminClient()->forCompany($autre)->create();

        $this->actingAs($adminB)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => false])
            ->assertNotFound();

        $this->assertTrue(Prospect::query()->acrossAllCompanies()->withArchived()->find($prospect->id)?->isArchived());
    }
}
