<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Models\Appointment;
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
 * Rendez-vous : périmètre de rôle et lecture seule — revue du lot 9.
 *
 * Trois défauts trouvés par la revue indépendante :
 *
 * - un rendez-vous d'une fiche effacée ou archivée restait modifiable : un
 *   PATCH y remettait un lieu et des notes, donc des données personnelles ;
 * - créer un rendez-vous sur n'importe quel prospect de l'entreprise
 *   renvoyait sa fiche complète, hors de tout périmètre ;
 * - un rendez-vous sans commercial était visible de toute l'équipe, quel que
 *   soit le projet, alors que les prospects non affectés sont bornés aux
 *   projets ouverts à l'utilisateur (règle R6, correction C9).
 */
class AppointmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $commercial;

    private Project $projetDuCommercial;

    private Project $autreProjet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->for($this->company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($this->company)->create();
        $this->commercial = User::factory()->commercial()->forCompany($this->company)->create();

        $this->projetDuCommercial = $this->projet();
        $this->projetDuCommercial->members()->attach($this->commercial->id);
        $this->autreProjet = $this->projet();
    }

    private function projet(): Project
    {
        $projet = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($projet);

        return $projet->fresh() ?? $projet;
    }

    private function rendezVous(Prospect $prospect, ?User $commercial = null): Appointment
    {
        return Appointment::factory()->forProspect($prospect)
            ->withStatus(AppointmentStatus::Planned)
            ->at(now()->addDays(3)->toDateTimeString())
            ->create(['user_id' => $commercial?->id]);
    }

    public function test_le_rendez_vous_d_une_fiche_effacee_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $prospect = Prospect::factory()->forProject($this->projetDuCommercial)->create();
        $rdv = $this->rendezVous($prospect, $this->admin);
        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/appointments/{$rdv->id}", ['location' => 'Chez lui', 'notes' => 'Hery préfère le matin'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'erasure.read_only');
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/appointments/{$rdv->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'erasure.read_only');

        $this->assertNull($rdv->fresh()?->location);
    }

    public function test_le_rendez_vous_d_un_prospect_archive_ne_se_modifie_pas(): void
    {
        $prospect = Prospect::factory()->forProject($this->projetDuCommercial)->create();
        $rdv = $this->rendezVous($prospect, $this->admin);
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/appointments/{$rdv->id}", ['status' => AppointmentStatus::Completed->value])
            ->assertStatus(422)
            ->assertJsonPath('code', 'archive.read_only');
    }

    public function test_un_commercial_ne_planifie_pas_sur_le_prospect_d_un_collegue(): void
    {
        $collegue = User::factory()->commercial()->forCompany($this->company)->create();
        $prospect = Prospect::factory()->forProject($this->projetDuCommercial)->assignedTo($collegue)->create();

        $reponse = $this->actingAs($this->commercial)->postJson('/api/v1/appointments', [
            'prospect_id' => $prospect->id,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'type' => AppointmentType::Phone->value,
        ]);

        $this->assertContains($reponse->status(), [403, 404]);
        $this->assertStringNotContainsString((string) $prospect->phone, $reponse->getContent() ?: '');
        $this->assertSame(0, Appointment::query()->acrossAllCompanies()->where('prospect_id', $prospect->id)->count());
    }

    public function test_un_rendez_vous_sans_commercial_hors_de_ses_projets_lui_reste_cache(): void
    {
        $horsProjet = $this->rendezVous(Prospect::factory()->forProject($this->autreProjet)->unassigned()->create());
        $dansSonProjet = $this->rendezVous(Prospect::factory()->forProject($this->projetDuCommercial)->unassigned()->create());

        /** @var list<array{id: int}> $liste */
        $liste = $this->actingAs($this->commercial)
            ->getJson('/api/v1/appointments?from='.now()->toDateString().'&to='.now()->addDays(7)->toDateString())
            ->assertOk()
            ->json('data');
        $ids = collect($liste)->pluck('id');

        $this->assertTrue($ids->contains($dansSonProjet->id));
        $this->assertFalse($ids->contains($horsProjet->id));

        $this->actingAs($this->commercial)->getJson("/api/v1/appointments/{$horsProjet->id}")->assertForbidden();
    }
}
