<?php

namespace Tests\Feature\Projects;

use App\Enums\ActivityType;
use App\Models\Company;
use App\Models\PipelineStage;
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
 * Santé du pipeline : durée moyenne par étape et prospects immobiles.
 *
 * La répartition par étape dit où sont les prospects, pas où ils s'arrêtent.
 * Ces deux mesures répondent à la seconde question, et se reconstituent depuis
 * `prospect_activities` — d'où l'insistance du plan sur le fait d'écrire ce
 * journal dès le premier changement d'étape.
 */
class PipelineHealthTest extends TestCase
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

    /** Déplace un prospect en inscrivant l'événement à une date choisie. */
    private function deplacer(
        Prospect $prospect,
        PipelineStage $de,
        PipelineStage $vers,
        string $quand,
    ): void {
        ProspectActivity::factory()->create([
            'prospect_id' => $prospect->id,
            'company_id' => $prospect->company_id,
            'type' => ActivityType::StageChanged,
            'payload' => [
                'from_id' => $de->id,
                'from' => $de->name,
                'to_id' => $vers->id,
                'to' => $vers->name,
            ],
            'occurred_at' => $quand,
        ]);

        $prospect->forceFill(['stage_id' => $vers->id])->saveQuietly();
    }

    /**
     * Santé du pipeline, indexée par identifiant d'étape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sante(User $viewer, Project $projet): array
    {
        $reponse = $this->actingAs($viewer)
            ->getJson("/api/v1/projects/{$projet->id}/pipeline-health")
            ->assertOk();

        /** @var list<array<string, mixed>> $etapes */
        $etapes = $reponse->json('data.stages');

        $indexees = [];

        foreach ($etapes as $etape) {
            $indexees[(int) $etape['stage_id']] = $etape;
        }

        return $indexees;
    }

    public function test_la_premiere_etape_se_mesure_depuis_la_creation_du_prospect(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etapes = $projet->stages;

        $prospect = Prospect::factory()->forProject($projet)->create([
            'created_at' => now()->subDays(30),
            'stage_id' => $etapes[0]->id,
        ]);

        // Le prospect a passé dix jours dans « Nouveau » avant d'en sortir.
        // L'activité `created` ne conserve que le nom de l'étape, pas son
        // identifiant : c'est le `from_id` de ce changement qui l'attribue.
        $this->deplacer($prospect, $etapes[0], $etapes[1], now()->subDays(20)->toDateTimeString());

        $sante = $this->sante($admin, $projet);

        // Comparaison numérique et non stricte : la valeur traverse JSON, qui ne
        // distingue pas 10 de 10.0.
        $this->assertEqualsWithDelta(10, $sante[$etapes[0]->id]['duree_moyenne_jours'], 0.05);
        $this->assertSame(1, $sante[$etapes[0]->id]['passages']);
    }

    public function test_un_sejour_en_cours_ne_compte_pas_dans_la_moyenne(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etapes = $projet->stages;

        $prospect = Prospect::factory()->forProject($projet)->create([
            'created_at' => now()->subDays(60),
            'stage_id' => $etapes[0]->id,
        ]);

        $this->deplacer($prospect, $etapes[0], $etapes[1], now()->subDays(50)->toDateTimeString());

        $sante = $this->sante($admin, $projet);

        // Le prospect est dans « Contacté » depuis cinquante jours, mais il n'en
        // est pas encore sorti : mêler ce temps d'attente au temps de franchissement
        // confondrait deux grandeurs. L'attente est mesurée par les immobiles.
        $this->assertNull($sante[$etapes[1]->id]['duree_moyenne_jours']);
        $this->assertSame(0, $sante[$etapes[1]->id]['passages']);
        $this->assertSame(1, $sante[$etapes[1]->id]['immobiles']);
    }

    public function test_la_moyenne_porte_sur_tous_les_passages(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etapes = $projet->stages;

        foreach ([4, 10] as $index => $jours) {
            $prospect = Prospect::factory()->forProject($projet)->create([
                'created_at' => now()->subDays(40 - $index),
                'stage_id' => $etapes[0]->id,
            ]);

            $this->deplacer(
                $prospect,
                $etapes[0],
                $etapes[1],
                now()->subDays(40 - $index - $jours)->toDateTimeString(),
            );
        }

        $sante = $this->sante($admin, $projet);

        // Moyenne de 4 et 10 jours.
        $this->assertEqualsWithDelta(7, $sante[$etapes[0]->id]['duree_moyenne_jours'], 0.05);
        $this->assertSame(2, $sante[$etapes[0]->id]['passages']);
    }

    public function test_un_dossier_clos_n_est_jamais_immobile(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etapes = $projet->stages;
        $gagnante = $etapes->firstWhere('is_won', true);

        // Sans mouvement depuis trois mois, mais converti : il ne bouge plus
        // par construction, et le compter ferait grossir l'alerte à mesure que
        // le projet réussit.
        Prospect::factory()->forProject($projet)->create([
            'created_at' => now()->subDays(90),
            'stage_id' => $gagnante->id,
            'converted_at' => now()->subDays(80),
        ]);

        Prospect::factory()->forProject($projet)->create([
            'created_at' => now()->subDays(90),
            'stage_id' => $etapes[1]->id,
        ]);

        $sante = $this->sante($admin, $projet);

        $this->assertSame(0, $sante[$gagnante->id]['immobiles']);
        $this->assertSame(1, $sante[$etapes[1]->id]['immobiles']);
    }

    public function test_un_prospect_recent_n_est_pas_immobile(): void
    {
        $projet = $this->projet();
        $admin = User::factory()->adminClient()->create(['company_id' => $projet->company_id]);
        $etapes = $projet->stages;

        Prospect::factory()->forProject($projet)->create([
            'created_at' => now()->subDays(3),
            'stage_id' => $etapes[1]->id,
        ]);

        $sante = $this->sante($admin, $projet);

        $this->assertSame(0, $sante[$etapes[1]->id]['immobiles']);
        $this->assertSame(1, $sante[$etapes[1]->id]['total']);
    }

    public function test_un_commercial_n_accede_pas_a_la_sante_du_pipeline(): void
    {
        $projet = $this->projet();
        $commercial = User::factory()->commercial()->create(['company_id' => $projet->company_id]);
        $projet->members()->attach($commercial);

        // Agréger l'activité d'une équipe relève de l'encadrement, comme les
        // rapports : un commercial n'a pas à connaître le rythme des autres.
        $this->actingAs($commercial)
            ->getJson("/api/v1/projects/{$projet->id}/pipeline-health")
            ->assertForbidden();
    }

    public function test_le_manager_ne_voit_que_son_perimetre(): void
    {
        $projet = $this->projet();
        $manager = User::factory()->manager()->create(['company_id' => $projet->company_id]);
        $sien = User::factory()->commercial()->create([
            'company_id' => $projet->company_id,
            'manager_id' => $manager->id,
        ]);
        $autre = User::factory()->commercial()->create(['company_id' => $projet->company_id]);

        $projet->members()->attach($manager);
        $etapes = $projet->stages;

        Prospect::factory()->forProject($projet)->assignedTo($sien)->create([
            'stage_id' => $etapes[1]->id,
        ]);
        Prospect::factory()->forProject($projet)->assignedTo($autre)->count(4)->create([
            'stage_id' => $etapes[1]->id,
        ]);

        $sante = $this->sante($manager, $projet);

        $this->assertSame(1, $sante[$etapes[1]->id]['total']);
    }

    public function test_une_autre_entreprise_n_atteint_pas_ce_projet(): void
    {
        $projetA = $this->projet();
        $projetB = $this->projet();

        $adminB = User::factory()->adminClient()->create(['company_id' => $projetB->company_id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/projects/{$projetA->id}/pipeline-health")
            ->assertNotFound();
    }
}
