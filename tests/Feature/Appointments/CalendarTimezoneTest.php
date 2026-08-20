<?php

namespace Tests\Feature\Appointments;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PipelineService;
use App\Support\TimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Décision §10.5 : UTC en base, conversion à la seule frontière de
 * présentation, fuseau porté par l'entreprise.
 *
 * Ces tests portent sur le piège central du lot : « les rendez-vous du jour »
 * doit désigner la journée **locale** du client, pas celle du serveur. Comparer
 * une colonne UTC à une date calculée ailleurs ampute la journée aux deux
 * extrémités, et l'erreur est silencieuse — la liste est simplement incomplète.
 */
class CalendarTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Antananarivo est à UTC+3 toute l'année : un rendez-vous à 01h00 locale
     * est enregistré la veille à 22h00 UTC.
     */
    private const FUSEAU = 'Indian/Antananarivo';

    /**
     * @return array{Prospect, User}
     */
    private function contexte(string $fuseau = self::FUSEAU): array
    {
        $company = Company::factory()->create(['timezone' => $fuseau]);
        Subscription::factory()->for($company)->for(Plan::factory()->enterprise())->create();

        $project = Project::factory()->forCompany($company)->create();
        app(PipelineService::class)->createDefaultStages($project);

        $prospect = Prospect::factory()->forProject($project->fresh())->create();
        $admin = User::factory()->adminClient()->forCompany($company)->create();

        return [$prospect, $admin];
    }

    public function test_les_bornes_d_une_journee_sont_calculees_dans_le_fuseau_de_l_entreprise(): void
    {
        [$ouverture, $fermeture] = TimezoneResolver::dayBounds(
            self::FUSEAU,
            CarbonImmutable::parse('2026-08-20', self::FUSEAU),
        );

        // 20 août 00h00 à Antananarivo = 19 août 21h00 UTC.
        $this->assertSame('2026-08-19 21:00:00', $ouverture->toDateTimeString());
        $this->assertSame('2026-08-20 20:59:59', $fermeture->toDateTimeString());
    }

    public function test_un_rendez_vous_de_debut_de_journee_locale_reste_dans_sa_journee(): void
    {
        [$prospect, $admin] = $this->contexte();

        // 01h00 locale le 20 août = 22h00 UTC le 19. Un filtre naïf sur la
        // date UTC le rangerait la veille et il disparaîtrait de l'agenda.
        Appointment::factory()->forProspect($prospect)->at('2026-08-19 22:00:00')->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Et il n'apparaît pas la veille.
        $this->actingAs($admin)
            ->getJson('/api/v1/appointments?from=2026-08-19&to=2026-08-19')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_un_rendez_vous_de_fin_de_journee_locale_reste_dans_sa_journee(): void
    {
        [$prospect, $admin] = $this->contexte();

        // 23h30 locale le 20 août = 20h30 UTC le même jour.
        Appointment::factory()->forProspect($prospect)->at('2026-08-20 20:30:00')->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_deux_entreprises_de_fuseaux_differents_voient_des_journees_differentes(): void
    {
        [$prospectMg, $adminMg] = $this->contexte('Indian/Antananarivo');
        [$prospectFr, $adminFr] = $this->contexte('Europe/Paris');

        // 22h00 UTC le 19 août : le 20 à 01h00 à Antananarivo (+3), mais
        // encore le 20 à 00h00 à Paris (+2 en été). Les deux tombent le 20.
        Appointment::factory()->forProspect($prospectMg)->at('2026-08-19 22:00:00')->create();
        Appointment::factory()->forProspect($prospectFr)->at('2026-08-19 22:00:00')->create();

        $this->actingAs($adminMg)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($adminFr)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 21h30 UTC le 19 : minuit trente le 20 à Antananarivo, mais encore
        // 23h30 le 19 à Paris. La même instant tombe dans deux journées
        // différentes selon le fuseau — c'est exactement ce que le calendrier
        // doit refléter.
        Appointment::factory()->forProspect($prospectMg)->at('2026-08-19 21:30:00')->create();
        Appointment::factory()->forProspect($prospectFr)->at('2026-08-19 21:30:00')->create();

        $this->actingAs($adminMg)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($adminFr)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_un_fuseau_invalide_retombe_sur_le_defaut(): void
    {
        $company = Company::factory()->create();
        // Une valeur héritée d'un import ne doit pas faire échouer tout le
        // calendrier.
        $company->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();

        $this->assertSame(
            TimezoneResolver::DEFAULT,
            TimezoneResolver::forCompany($company->fresh())
        );
    }

    public function test_le_super_admin_sans_entreprise_a_un_fuseau_de_repli(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertSame(TimezoneResolver::DEFAULT, TimezoneResolver::for($superAdmin));
    }

    public function test_les_dates_sont_renvoyees_en_utc(): void
    {
        [$prospect, $admin] = $this->contexte();

        Appointment::factory()->forProspect($prospect)->at('2026-08-20 07:00:00')->create();

        $reponse = $this->actingAs($admin)
            ->getJson('/api/v1/appointments?from=2026-08-20&to=2026-08-20')
            ->assertOk();

        // Le contrat est en UTC : la conversion appartient au front, seul
        // endroit qui sait dans quel fuseau afficher.
        $this->assertStringEndsWith('+00:00', $reponse->json('data.0.scheduled_at'));
    }
}
