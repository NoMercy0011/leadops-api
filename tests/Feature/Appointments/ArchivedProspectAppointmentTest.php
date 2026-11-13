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
 * Rendez-vous d'un prospect archivé ou effacé — lot 9, règle R5.
 *
 * Un prospect archivé sort de tous les taux : ses rendez-vous ne comptent donc
 * dans aucune tuile du tableau de bord. Mais l'archivage range, il ne cache
 * pas : le calendrier les montre toujours, marqués « archivé ». Avant le
 * lot 9, la relation `prospect` était filtrée par l'archivage et revenait
 * `null`, d'où « Prospect inconnu » à l'écran.
 */
class ArchivedProspectAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();
        $this->admin = User::factory()->adminClient()->forCompany($company)->create();

        $this->projet = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($this->projet);
        $this->projet = $this->projet->fresh() ?? $this->projet;
    }

    private function rendezVousDansTroisJours(Prospect $prospect): Appointment
    {
        return Appointment::factory()->forProspect($prospect)
            ->withStatus(AppointmentStatus::Planned)
            ->at(now()->addDays(3)->toDateTimeString())
            ->create();
    }

    private function archiver(Prospect $prospect): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertOk();
    }

    public function test_les_rendez_vous_d_un_prospect_archive_sortent_des_tuiles(): void
    {
        $actif = Prospect::factory()->forProject($this->projet)->create();
        $archive = Prospect::factory()->forProject($this->projet)->create();
        $this->rendezVousDansTroisJours($actif);
        $this->rendezVousDansTroisJours($archive);

        $this->archiver($archive);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.appointments.upcoming', 1);
    }

    public function test_le_calendrier_montre_le_rendez_vous_d_un_prospect_archive_marque_archive(): void
    {
        $archive = Prospect::factory()->forProject($this->projet)->create(['first_name' => 'Voahangy']);
        $rdv = $this->rendezVousDansTroisJours($archive);
        $this->archiver($archive);

        $reponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/appointments?from='.now()->toDateString().'&to='.now()->addDays(7)->toDateString())
            ->assertOk();

        /** @var list<array<string, mixed>> $liste */
        $liste = $reponse->json('data');
        $ligne = collect($liste)->firstWhere('id', $rdv->id);

        $this->assertNotNull($ligne, 'le rendez-vous doit rester au calendrier');
        $this->assertIsArray($ligne['prospect']);
        $this->assertTrue($ligne['prospect']['is_archived']);
        $this->assertSame('Voahangy', $ligne['prospect']['first_name']);
    }

    public function test_le_rendez_vous_d_une_fiche_effacee_affiche_prospect_efface(): void
    {
        $prospect = Prospect::factory()->forProject($this->projet)->create();
        $rdv = $this->rendezVousDansTroisJours($prospect);

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/appointments/{$rdv->id}")
            ->assertOk()
            ->assertJsonPath('data.prospect.full_name', 'Prospect effacé');
    }

    public function test_planifier_un_rendez_vous_sur_un_prospect_archive_est_refuse(): void
    {
        $archive = Prospect::factory()->forProject($this->projet)->create();
        $this->archiver($archive);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/appointments', [
                'prospect_id' => $archive->id,
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
                'type' => AppointmentType::Phone->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'archive.read_only');
    }
}
