<?php

namespace Tests\Feature\Prospects;

use App\Enums\ActivityType;
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
 * Critère de sortie du lot 4 : un commercial traite un prospect de bout en
 * bout, et l'historique est complet et horodaté.
 */
class ProspectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
        $this->commercial = User::factory()->commercial()->forCompany($company)->create();
        $this->project->members()->attach($this->commercial);
    }

    public function test_un_prospect_traverse_le_pipeline_et_laisse_une_trace_complete(): void
    {
        // 1. Création
        $reponse = $this->actingAs($this->admin)->postJson('/api/v1/prospects', [
            'project_id' => $this->project->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '034 12 345 67',
            'email' => 'Jean@Example.COM',
            'assigned_user_id' => $this->commercial->id,
        ]);

        $reponse->assertCreated();
        $prospect = Prospect::query()->acrossAllCompanies()->firstOrFail();

        // Le prospect entre à la première étape du pipeline, pas à une étape
        // choisie au hasard.
        $premiere = $this->project->stages()->orderBy('position')->first();
        $this->assertSame($premiere->id, $prospect->stage_id);

        // Les coordonnées sont normalisées à l'écriture — décision §10.1.
        $this->assertSame('+261341234567', $prospect->phone_normalized);
        $this->assertSame('jean@example.com', $prospect->email_normalized);
        // La saisie d'origine est conservée telle quelle.
        $this->assertSame('034 12 345 67', $prospect->phone);

        // 2. Le commercial fait avancer le prospect étape par étape
        $etapes = $this->project->stages()->orderBy('position')->get();

        foreach ($etapes->slice(1, 3) as $etape) {
            $this->actingAs($this->commercial)
                ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $etape->id])
                ->assertOk()
                ->assertJsonPath('data.stage.id', $etape->id);
        }

        // 3. Une note et une relance
        $this->actingAs($this->commercial)
            ->postJson("/api/v1/prospects/{$prospect->id}/notes", ['note' => 'Appel effectué, rappeler lundi.'])
            ->assertCreated();

        $this->actingAs($this->commercial)
            ->patchJson("/api/v1/prospects/{$prospect->id}/next-action", [
                'next_action_at' => now()->addDays(3)->toIso8601String(),
                'next_action_note' => 'Rendez-vous téléphonique',
            ])
            ->assertOk();

        // 4. Conversion
        $gagnante = $etapes->firstWhere('is_won', true);

        $this->actingAs($this->commercial)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $gagnante->id])
            ->assertOk();

        $prospect->refresh();
        $this->assertNotNull($prospect->converted_at, 'La date de conversion alimente les durées de cycle du lot 7.');
        $this->assertNull($prospect->lost_at);

        // 5. L'historique doit être complet et ordonné
        $journal = $this->actingAs($this->commercial)
            ->getJson("/api/v1/prospects/{$prospect->id}/activities")
            ->assertOk();

        $types = array_column($journal->json('data'), 'type');

        $this->assertContains(ActivityType::Created->value, $types);
        $this->assertContains(ActivityType::NoteAdded->value, $types);
        $this->assertContains(ActivityType::NextActionPlanned->value, $types);
        $this->assertSame(
            4,
            count(array_filter($types, fn (string $t) => $t === ActivityType::StageChanged->value)),
            'Trois avancées plus la conversion.'
        );

        // Chaque ligne est horodatée.
        foreach ($journal->json('data') as $ligne) {
            $this->assertNotNull($ligne['occurred_at']);
        }
    }

    public function test_un_retour_en_arriere_rouvre_le_dossier(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();
        $etapes = $this->project->stages()->orderBy('position')->get();
        $gagnante = $etapes->firstWhere('is_won', true);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $gagnante->id])
            ->assertOk();

        $this->assertNotNull($prospect->fresh()->converted_at);

        // Sans remise à zéro, le prospect compterait comme converti *et* actif
        // dans les rapports.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $etapes->first()->id])
            ->assertOk();

        $this->assertNull($prospect->fresh()->converted_at);
    }

    public function test_la_date_de_conversion_n_est_pas_ecrasee_par_un_aller_retour(): void
    {
        $prospect = Prospect::factory()->forProject($this->project)->create();
        $etapes = $this->project->stages()->orderBy('position')->get();
        $gagnante = $etapes->firstWhere('is_won', true);

        $this->travelTo(now()->subDays(10));
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $gagnante->id]);
        $premiereConversion = $prospect->fresh()->converted_at;

        $this->travelBack();

        // Repasser par la même étape gagnante ne doit pas repousser la date :
        // la durée de cycle en dépend.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $etapes->first()->id]);
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $gagnante->id]);

        $this->assertTrue(
            $prospect->fresh()->converted_at->greaterThan($premiereConversion),
            'Après réouverture, la conversion est bien un nouvel événement.'
        );
    }

    public function test_une_etape_d_un_autre_projet_est_refusee(): void
    {
        $autreProjet = Project::factory()->forCompany($this->project->company)->create();
        app(PipelineService::class)->createDefaultStages($autreProjet);

        $prospect = Prospect::factory()->forProject($this->project)->create();
        $etapeEtrangere = $autreProjet->stages()->first();

        // Les deux projets appartiennent à la même entreprise : le
        // cloisonnement tenant ne suffit pas, c'est le service qui tranche.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/stage", ['stage_id' => $etapeEtrangere->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stage_id');
    }
}
