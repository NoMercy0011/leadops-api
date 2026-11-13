<?php

namespace Tests\Feature\Prospects;

use App\Enums\AppointmentType;
use App\Enums\QuestionType;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectAnswer;
use App\Models\Question;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Effacement des données personnelles d'un prospect — lot 9, règle R4.
 *
 * L'effacement est une **anonymisation**, pas une suppression : l'identité, les
 * coordonnées et tout texte libre disparaissent, mais l'étape, les dates et les
 * jalons restent, pour que les taux du projet ne bougent pas. La fiche porte
 * ensuite `erased_at`, et n'accepte plus aucune écriture.
 *
 * Il doit rester possible même abonnement suspendu : une demande d'effacement
 * ne peut pas attendre que le client règle sa facture.
 */
class ProspectErasureTest extends TestCase
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

    private function prospect(): Prospect
    {
        return Prospect::factory()->forProject($this->projet)->create([
            'first_name' => 'Hery',
            'last_name' => 'Rakoto',
            'company_name' => 'Rakoto SARL',
            'phone' => '034 12 345 67',
            'phone_normalized' => '+261341234567',
            'email' => 'hery@example.mg',
            'email_normalized' => 'hery@example.mg',
            'address' => 'Lot II A 12, Antananarivo',
            'notes' => 'Préfère être appelé le matin.',
            'next_action_note' => 'Rappeler pour le devis',
        ]);
    }

    private function relire(Prospect $prospect): Prospect
    {
        return Prospect::query()->acrossAllCompanies()->withArchived()->findOrFail($prospect->id);
    }

    public function test_l_effacement_vide_l_identite_et_les_coordonnees(): void
    {
        $prospect = $this->prospect();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/erase")
            ->assertOk()
            ->assertJsonPath('data.is_erased', true)
            ->assertJsonPath('data.full_name', 'Prospect effacé')
            ->assertJsonPath('data.email', null);

        $efface = $this->relire($prospect);
        $this->assertNotNull($efface->erased_at);
        foreach (['last_name', 'company_name', 'phone', 'phone_normalized', 'email', 'email_normalized', 'address', 'notes', 'next_action_note'] as $champ) {
            $this->assertNull($efface->getAttribute($champ), "{$champ} doit être vidé");
        }
        $this->assertSame('', $efface->first_name);
    }

    public function test_l_effacement_garde_l_etape_l_affectation_et_les_jalons(): void
    {
        $prospect = $this->prospect();
        $prospect->forceFill(['converted_at' => now()->subDay(), 'qualified_at' => now()->subDays(3)])->save();

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $efface = $this->relire($prospect);
        $this->assertSame($prospect->stage_id, $efface->stage_id);
        $this->assertSame($prospect->project_id, $efface->project_id);
        $this->assertNotNull($efface->converted_at);
        $this->assertNotNull($efface->qualified_at);
    }

    public function test_les_reponses_libres_et_les_dates_sont_videes_les_autres_gardees(): void
    {
        $prospect = $this->prospect();
        $texte = Question::factory()->forProject($this->projet)->ofType(QuestionType::Text)->create();
        $date = Question::factory()->forProject($this->projet)->ofType(QuestionType::Date)->create();
        $nombre = Question::factory()->forProject($this->projet)->ofType(QuestionType::Number)->create();
        $ouiNon = Question::factory()->forProject($this->projet)->ofType(QuestionType::YesNo)->create();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/prospects/{$prospect->id}/questionnaire", ['answers' => [
                $texte->id => 'Mutuelle actuelle : aucune',
                $date->id => '1984-03-12',
                $nombre->id => 4,
                $ouiNon->id => true,
            ]])
            ->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $restantes = ProspectAnswer::query()->where('prospect_id', $prospect->id)->pluck('question_id')->all();
        sort($restantes);
        $attendues = [$nombre->id, $ouiNon->id];
        sort($attendues);
        $this->assertSame($attendues, $restantes);
    }

    public function test_les_notes_et_le_lieu_des_rendez_vous_sont_vides(): void
    {
        $prospect = $this->prospect();

        $this->actingAs($this->admin)->postJson('/api/v1/appointments', [
            'prospect_id' => $prospect->id,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
            'type' => AppointmentType::OnSite->value,
            'location' => 'Chez lui, Ivandry',
            'notes' => 'Venir avec la brochure famille',
        ])->assertCreated();

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $rdv = Appointment::query()->where('prospect_id', $prospect->id)->firstOrFail();
        $this->assertNull($rdv->location);
        $this->assertNull($rdv->notes);
    }

    public function test_le_journal_garde_la_trace_de_l_effacement_sans_donnee_personnelle(): void
    {
        $prospect = $this->prospect();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/notes", ['note' => 'Hery préfère le matin'])
            ->assertSuccessful();

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $journal = ProspectActivity::query()->where('prospect_id', $prospect->id)->get();
        $this->assertTrue($journal->contains(fn (ProspectActivity $a) => $a->type->value === 'erased' && $a->user_id === $this->admin->id));
        $this->assertStringNotContainsString('Hery', (string) json_encode($journal->pluck('payload')->all()));
    }

    public function test_les_autres_fiches_de_la_personne_sont_signalees_sans_etre_effacees(): void
    {
        $prospect = $this->prospect();
        $autreProjet = Project::factory()->forCompany($this->company)->create();
        app(PipelineService::class)->createDefaultStages($autreProjet);
        $double = Prospect::factory()->forProject($autreProjet->fresh() ?? $autreProjet)->create([
            'first_name' => 'H.', 'phone_normalized' => '+261341234567',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/erase")
            ->assertOk()
            ->assertJsonPath('autres_fiches.0.id', $double->id)
            ->assertJsonPath('autres_fiches.0.project_id', $autreProjet->id);

        $this->assertNull($this->relire($double)->erased_at);
    }

    public function test_l_effacement_reste_possible_abonnement_suspendu(): void
    {
        $prospect = $this->prospect();
        $this->company->subscription()->update(['status' => 'suspended']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/erase")
            ->assertOk();
    }

    public function test_seul_l_admin_client_efface(): void
    {
        $prospect = $this->prospect();
        $manager = User::factory()->manager()->forCompany($this->company)->create();
        $commercial = User::factory()->commercial()->forCompany($this->company)->managedBy($manager)->create();
        $prospect->forceFill(['assigned_user_id' => $commercial->id])->save();

        $this->actingAs($manager)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertForbidden();
        $this->actingAs($commercial)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertForbidden();

        $this->assertNull($this->relire($prospect)->erased_at);
    }

    public function test_une_autre_entreprise_ne_peut_pas_effacer(): void
    {
        $prospect = $this->prospect();
        $autre = Company::factory()->create();
        Subscription::factory()->for($autre)->for(Plan::factory()->enterprise())->create();
        $adminB = User::factory()->adminClient()->forCompany($autre)->create();

        $this->actingAs($adminB)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertNotFound();

        $this->assertNull($this->relire($prospect)->erased_at);
    }

    public function test_un_second_effacement_est_refuse(): void
    {
        $prospect = $this->prospect();
        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/prospects/{$prospect->id}/erase")
            ->assertStatus(409)
            ->assertJsonPath('code', 'erasure.already_erased');
    }

    public function test_une_fiche_effacee_refuse_toute_ecriture(): void
    {
        $prospect = $this->prospect();
        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}", ['first_name' => 'Hery'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'erasure.read_only');
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'erasure.read_only');
    }

    public function test_un_prospect_archive_peut_etre_efface(): void
    {
        $prospect = $this->prospect();
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/prospects/{$prospect->id}/archive", ['archived' => true])
            ->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/prospects/{$prospect->id}/erase")->assertOk();
    }

    public function test_la_suppression_physique_n_existe_plus(): void
    {
        $prospect = $this->prospect();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/prospects/{$prospect->id}")
            ->assertStatus(405);

        $this->assertNotNull(Prospect::query()->find($prospect->id));
    }
}
