<?php

namespace Tests\Feature\Appointments;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $admin;

    private User $commercial;

    private Prospect $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create(['timezone' => 'Indian/Antananarivo']);
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $this->project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->project);
        $this->project->refresh();

        $this->admin = User::factory()->adminClient()->forCompany($company)->create();
        $this->commercial = User::factory()->commercial()->forCompany($company)->create();

        $this->prospect = Prospect::factory()
            ->forProject($this->project)
            ->assignedTo($this->commercial)
            ->create();
    }

    public function test_un_rendez_vous_herite_du_projet_et_du_commercial_du_prospect(): void
    {
        $reponse = $this->actingAs($this->commercial)->postJson('/api/v1/appointments', [
            'prospect_id' => $this->prospect->id,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'type' => AppointmentType::Phone->value,
        ]);

        $reponse->assertCreated()
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.project_id', $this->project->id)
            // Le commercial du prospect reprend le rendez-vous par défaut :
            // l'imposer en saisie serait une redondance à chaque planification.
            ->assertJsonPath('data.user_id', $this->commercial->id);
    }

    public function test_le_projet_ne_peut_pas_diverger_de_celui_du_prospect(): void
    {
        $autreProjet = Project::factory()->forCompany($this->project->company)->create();

        $reponse = $this->actingAs($this->admin)->postJson('/api/v1/appointments', [
            'prospect_id' => $this->prospect->id,
            'project_id' => $autreProjet->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'type' => AppointmentType::Video->value,
        ]);

        // Le projet fourni est ignoré : il vient du prospect, seule source
        // cohérente.
        $reponse->assertCreated()->assertJsonPath('data.project_id', $this->project->id);
    }

    public function test_un_rendez_vous_physique_exige_une_adresse(): void
    {
        $this->actingAs($this->commercial)->postJson('/api/v1/appointments', [
            'prospect_id' => $this->prospect->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'type' => AppointmentType::OnSite->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');

        // Un appel téléphonique, lui, n'en demande pas : exiger un lieu là
        // produirait un champ vide de sens rempli n'importe comment.
        $this->actingAs($this->commercial)->postJson('/api/v1/appointments', [
            'prospect_id' => $this->prospect->id,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'type' => AppointmentType::Phone->value,
        ])->assertCreated();
    }

    /**
     * @return array<string, array{AppointmentStatus}>
     */
    public static function statuts(): array
    {
        return [
            'planifie' => [AppointmentStatus::Planned],
            'realise' => [AppointmentStatus::Completed],
            'annule' => [AppointmentStatus::Cancelled],
            'absent' => [AppointmentStatus::NoShow],
        ];
    }

    #[DataProvider('statuts')]
    public function test_les_quatre_statuts_de_l_esquisse_sont_gerés(AppointmentStatus $statut): void
    {
        $rdv = Appointment::factory()->forProspect($this->prospect)->create();

        $this->actingAs($this->commercial)
            ->patchJson("/api/v1/appointments/{$rdv->id}", ['status' => $statut->value])
            ->assertOk()
            ->assertJsonPath('data.status', $statut->value)
            ->assertJsonPath('data.status_label', $statut->label());
    }

    public function test_un_rendez_vous_planifie_et_passe_est_signale_en_retard(): void
    {
        $passe = Appointment::factory()
            ->forProspect($this->prospect)
            ->at(now()->subDay()->toDateTimeString())
            ->create();

        $futur = Appointment::factory()
            ->forProspect($this->prospect)
            ->at(now()->addDay()->toDateTimeString())
            ->create();

        // État calculé et non statut supplémentaire : le commercial doit clore
        // le rendez-vous en disant s'il a eu lieu, a été annulé, ou si le
        // prospect ne s'est pas présenté.
        $this->assertTrue($passe->isOverdue());
        $this->assertFalse($futur->isOverdue());

        // Une fois clos, il n'est plus en retard quelle que soit sa date.
        $passe->update(['status' => AppointmentStatus::NoShow]);
        $this->assertFalse($passe->fresh()->isOverdue());
    }

    public function test_chaque_evenement_apparait_dans_le_journal_du_prospect(): void
    {
        ProspectActivity::query()->delete();

        $reponse = $this->actingAs($this->commercial)->postJson('/api/v1/appointments', [
            'prospect_id' => $this->prospect->id,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'type' => AppointmentType::Video->value,
        ])->assertCreated();

        $id = $reponse->json('data.id');

        $this->actingAs($this->commercial)->patchJson("/api/v1/appointments/{$id}", [
            'scheduled_at' => now()->addDays(5)->toIso8601String(),
        ])->assertOk();

        $this->actingAs($this->commercial)->patchJson("/api/v1/appointments/{$id}", [
            'status' => AppointmentStatus::Completed->value,
        ])->assertOk();

        // Un rendez-vous est un événement de la vie du prospect : sans trace,
        // l'agenda et l'historique raconteraient deux histoires différentes.
        $types = ProspectActivity::query()
            ->acrossAllCompanies()
            ->pluck('type')
            ->map(fn ($t) => $t->value)
            ->all();

        $this->assertContains(ActivityType::AppointmentScheduled->value, $types);
        $this->assertContains(ActivityType::AppointmentRescheduled->value, $types);
        $this->assertContains(ActivityType::AppointmentStatusChanged->value, $types);
    }

    public function test_le_prospect_d_un_rendez_vous_ne_peut_pas_changer(): void
    {
        $rdv = Appointment::factory()->forProspect($this->prospect)->create();
        $autre = Prospect::factory()->forProject($this->project)->create();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/appointments/{$rdv->id}", ['prospect_id' => $autre->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prospect_id');
    }

    public function test_un_commercial_ne_reattribue_pas_un_rendez_vous(): void
    {
        $collegue = User::factory()->commercial()->forCompany($this->project->company)->create();
        $rdv = Appointment::factory()
            ->forProspect($this->prospect)
            ->assignedTo($this->commercial)
            ->create();

        // Sinon il se déchargerait de ses rendez-vous sur un collègue.
        $this->actingAs($this->commercial)
            ->patchJson("/api/v1/appointments/{$rdv->id}", ['user_id' => $collegue->id])
            ->assertForbidden();

        // Il reste libre de le déplacer et de le clore.
        $this->actingAs($this->commercial)
            ->patchJson("/api/v1/appointments/{$rdv->id}", [
                'scheduled_at' => now()->addDays(4)->toIso8601String(),
            ])
            ->assertOk();
    }

    public function test_une_entreprise_ne_voit_pas_les_rendez_vous_d_une_autre(): void
    {
        $autreCompany = Company::factory()->create();
        Subscription::factory()->for($autreCompany)->for(Plan::factory()->enterprise())->create();
        $autreProjet = Project::factory()->forCompany($autreCompany)->create();
        app(PipelineService::class)->createDefaultStages($autreProjet);
        $autreProspect = Prospect::factory()->forProject($autreProjet->fresh())->create();
        $rdvEtranger = Appointment::factory()->forProspect($autreProspect)->create();

        Appointment::factory()->forProspect($this->prospect)->count(2)->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/appointments?from='.now()->subMonth()->toDateString().'&to='.now()->addMonths(2)->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)
            ->getJson("/api/v1/appointments/{$rdvEtranger->id}")
            ->assertNotFound();
    }

    public function test_le_filtre_par_prospect_ignore_les_bornes_temporelles(): void
    {
        $autre = Prospect::factory()->forProject($this->project)->create();

        // Un rendez-vous très ancien et un très lointain : la fiche d'un
        // prospect doit montrer tout son historique, alors qu'une plage par
        // défaut les écarterait tous les deux.
        $ancien = Appointment::factory()->forProspect($this->prospect)->at('2020-01-15 09:00:00')->create();
        $lointain = Appointment::factory()->forProspect($this->prospect)->at('2030-06-10 09:00:00')->create();
        Appointment::factory()->forProspect($autre)->create();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/appointments?prospect_id={$this->prospect->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Sans le filtre, la plage par défaut écarte les deux extrêmes.
        // L'assertion porte sur leur absence et non sur un décompte, qui
        // dépendrait de la date d'exécution du test.
        $sansFiltre = $this->actingAs($this->admin)
            ->getJson('/api/v1/appointments')
            ->assertOk();

        $ids = array_column($sansFiltre->json('data'), 'id');

        $this->assertNotContains($ancien->id, $ids);
        $this->assertNotContains($lointain->id, $ids);
    }

    public function test_le_super_admin_n_accede_pas_a_l_agenda_commercial(): void
    {
        Appointment::factory()->forProspect($this->prospect)->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/appointments')
            ->assertForbidden();
    }
}
