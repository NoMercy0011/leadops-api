<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\ProjectStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectAnswer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityLogger;
use App\Services\PipelineService;
use App\Services\ProspectService;
use App\Services\QuestionnaireService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = collect([
            ['name' => 'Starter', 'slug' => 'starter', 'max_projects' => 2, 'max_users' => 5, 'max_prospects' => 1000, 'position' => 1],
            ['name' => 'Business', 'slug' => 'business', 'max_projects' => 10, 'max_users' => 25, 'max_prospects' => 10000, 'position' => 2],
            // NULL = illimité.
            ['name' => 'Enterprise', 'slug' => 'enterprise', 'max_projects' => null, 'max_users' => null, 'max_prospects' => null, 'position' => 3],
        ])->mapWithKeys(fn (array $attributs) => [
            $attributs['slug'] => Plan::query()->updateOrCreate(['slug' => $attributs['slug']], $attributs),
        ]);

        User::query()->updateOrCreate(
            ['email' => 'super@leadops.mg'],
            [
                'company_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::SuperAdmin,
                'email_verified_at' => now(),
            ]
        );

        // Deux entreprises, et non une seule : c'est le seul moyen de vérifier
        // le cloisonnement à la main pendant le développement.
        $this->creerEntreprise('ABC Commercial', 'abc', $plans['business'], 'abc.mg');
        $this->creerEntreprise('Delta Assurances', 'delta', $plans['starter'], 'delta.mg');
    }

    private function creerEntreprise(string $nom, string $slug, Plan $plan, string $domaine): void
    {
        $entreprise = Company::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $nom, 'timezone' => 'Indian/Antananarivo', 'default_country_code' => 'MG']
        );

        Subscription::query()->updateOrCreate(
            ['company_id' => $entreprise->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonths(2),
                'expires_at' => now()->addYear(),
            ]
        );

        $admin = $this->creerUtilisateur($entreprise->id, "Admin {$nom}", "admin@{$domaine}", UserRole::AdminClient);
        $manager = $this->creerUtilisateur($entreprise->id, "Manager {$nom}", "manager@{$domaine}", UserRole::Manager, $admin->id);

        $c1 = $this->creerUtilisateur($entreprise->id, "Commercial 1 {$nom}", "commercial1@{$domaine}", UserRole::Commercial, $manager->id);
        $c2 = $this->creerUtilisateur($entreprise->id, "Commercial 2 {$nom}", "commercial2@{$domaine}", UserRole::Commercial, $manager->id);

        $this->creerProjets($entreprise, [$c1, $c2]);
    }

    /**
     * Deux projets par entreprise, de variantes différentes.
     *
     * C'est le minimum pour vérifier à la main que les pipelines sont bien
     * indépendants : avec un seul projet, l'invariant n°2 ne se voit pas.
     *
     * @param  list<User>  $commerciaux
     */
    private function creerProjets(Company $entreprise, array $commerciaux): void
    {
        $variantes = collect(['Particulier', 'Entreprise'])
            ->mapWithKeys(fn (string $nom, int $index) => [
                $nom => Variant::query()->updateOrCreate(
                    ['company_id' => $entreprise->id, 'name' => $nom],
                    ['position' => $index + 1]
                ),
            ]);

        // Trois projets, dont **deux de la même variante** : sans cela, chaque
        // projet est seul de son axe et la comparaison entre offres n'a rien à
        // comparer. C'est précisément ce que la variante existe pour permettre
        // — un axe d'agrégation à un seul membre ne dit rien.
        $catalogue = [
            ['Assurance Santé', 'Assurance Santé Premium', 'Particuliers', 'Particulier'],
            ['Assurance Auto', 'Tous risques', 'Particuliers motorisés', 'Particulier'],
            ['Crédit Immobilier', 'Prêt à taux fixe', 'Ménages primo-accédants', 'Entreprise'],
        ];

        foreach ($catalogue as [$nomProjet, $produit, $cible, $variante]) {
            $existe = Project::query()
                ->acrossAllCompanies()
                ->where('company_id', $entreprise->id)
                ->where('name', $nomProjet)
                ->exists();

            if ($existe) {
                continue;
            }

            $projet = Project::query()->create([
                'company_id' => $entreprise->id,
                'variant_id' => $variantes[$variante]->id,
                'name' => $nomProjet,
                'product' => $produit,
                'target' => $cible,
                'status' => ProjectStatus::Active,
            ]);

            app(PipelineService::class)->createDefaultStages($projet);

            $projet->members()->sync(collect($commerciaux)->pluck('id')->all());

            // Avant les prospects : ce sont eux qui y répondent.
            $this->creerQuestionnaire($projet);

            $this->creerProspects($projet->fresh(), $commerciaux);
            $this->etalerHistorique($projet);
        }
    }

    /**
     * Répartit l'historique du projet sur les trois derniers mois.
     *
     * Le seeder crée tout dans la même seconde : les prospects naissent et
     * franchissent leurs étapes au même instant. Les durées moyennes par étape
     * ressortent alors toutes à zéro et aucun prospect n'est immobile — la
     * santé du pipeline est structurellement invisible sur une base neuve,
     * exactement comme l'étaient le questionnaire et les relances avant d'être
     * ajoutés ici.
     *
     * La création est reculée d'un délai croissant, puis les changements
     * d'étape sont étalés entre cette date et aujourd'hui. Le dernier
     * intervalle est volontairement long pour une partie des dossiers : c'est
     * ce qui produit des prospects immobiles, et donc l'alerte qui va avec.
     */
    private function etalerHistorique(Project $projet): void
    {
        $prospects = Prospect::query()
            ->acrossAllCompanies()
            ->where('project_id', $projet->id)
            ->orderBy('id')
            ->get();

        foreach ($prospects->values() as $rang => $prospect) {
            // De 90 jours pour le premier à une dizaine pour le dernier.
            $anciennete = 90 - ($rang * 7) % 80;
            $creation = now()->subDays($anciennete)->setTime(9, 0);

            $prospect->forceFill(['created_at' => $creation])->saveQuietly();

            $activites = ProspectActivity::query()
                ->where('prospect_id', $prospect->id)
                ->orderBy('id')
                ->get();

            if ($activites->isEmpty()) {
                continue;
            }

            // Un dossier sur trois s'arrête à mi-parcours : ses événements se
            // tassent dans la première moitié de la période, laissant un long
            // silence jusqu'à aujourd'hui.
            $stagne = $rang % 3 === 0;
            $etendue = $stagne ? intdiv($anciennete, 2) : $anciennete - 1;
            $pas = max(1, intdiv($etendue, max(1, $activites->count())));

            foreach ($activites->values() as $index => $activite) {
                $activite->forceFill([
                    'occurred_at' => $creation->copy()->addDays($pas * ($index + 1)),
                ])->saveQuietly();
            }
        }
    }

    /**
     * Prospects répartis sur le pipeline, dont quelques-uns non affectés.
     *
     * L'objectif n'est pas le volume mais la variété : un kanban dont toutes
     * les colonnes sont vides sauf la première ne permet pas de juger l'écran,
     * et sans prospect libre on ne peut pas essayer la distribution.
     *
     * @param  list<User>  $commerciaux
     */
    private function creerProspects(Project $projet, array $commerciaux): void
    {
        if (Prospect::query()->acrossAllCompanies()->where('project_id', $projet->id)->exists()) {
            return;
        }

        $etapes = $projet->stages()->orderBy('position')->get();
        $service = app(ProspectService::class);

        $noms = [
            ['Jean', 'Dupont'], ['Marie', 'Rakoto'], ['Paul', 'Martin'],
            ['Sarah', 'Randria'], ['Luc', 'Bernard'], ['Hery', 'Rasoa'],
            ['Nadia', 'Andria'], ['Eric', 'Petit'], ['Fara', 'Rabe'],
            ['Tovo', 'Rakotoson'], ['Aina', 'Ravelo'], ['Koto', 'Rajao'],
        ];

        foreach ($noms as $index => [$prenom, $nom]) {
            $etape = $etapes[$index % max(1, $etapes->count() - 2)];

            // Un prospect sur quatre reste libre, à distribuer.
            $commercial = $index % 4 === 3
                ? null
                : $commerciaux[$index % count($commerciaux)];

            // Deux défauts de données délibérés, sans quoi l'audit de qualité
            // du projet reste vide et l'écran ne peut pas être jugé.
            //
            // Le onzième reprend le numéro du deuxième, saisi au format local :
            // c'est le cas que la normalisation E.164 doit rattraper — comparer
            // les valeurs brutes ne trouverait rien. Le neuvième n'a ni
            // téléphone ni email, comme une ligne d'import incomplète.
            $doublonDe = $index === 10 ? 1 : null;
            $injoignable = $index === 8;

            $telephone = match (true) {
                $injoignable => null,
                // Forme nationale du numéro du deuxième prospect
                // (+261 33 100 00 37) : deux écritures, un seul numéro.
                $doublonDe !== null => '0331000037',
                default => '+2613'.(2 + $index % 3).str_pad((string) (1000000 + $index * 37), 7, '0', STR_PAD_LEFT),
            };

            $prospect = $service->create($projet, [
                'first_name' => $prenom,
                'last_name' => $nom,
                'phone' => $telephone,
                'email' => $injoignable
                    ? null
                    : mb_strtolower($prenom.'.'.$nom).'@example.com',
                'source' => ['Salon', 'Site web', 'Recommandation', 'Appel entrant'][$index % 4],
                'assigned_user_id' => $commercial?->id,
                'stage_id' => $etape->id,
            ]);

            if ($index % 3 === 0) {
                app(ActivityLogger::class)->noteAdded($prospect, 'Premier contact établi, à recontacter.');
            }

            // Quelques dossiers menés à leur terme : sans cela le tableau de
            // bord affiche 0 % partout, et rien ne permet de juger l'écran.
            // Le passage se fait par le service, seul à poser les jalons.
            //
            // La cadence est décalée d'un projet à l'autre : à cadence
            // identique, deux offres de la même variante ressortent au même
            // taux au dixième près, et l'écran de comparaison ne démontre rien
            // — or c'est précisément l'écart entre offres qu'il existe pour
            // montrer.
            $rythme = ($index + $projet->id) % 6;

            // Un projet sur deux conclut un dossier de plus par cycle. Le
            // décalage seul ne suffisait pas : il change *quels* dossiers se
            // concluent, pas *combien*, et les deux offres ressortaient au même
            // taux de conversion au dixième près.
            $conclut = $rythme === 2 || ($projet->id % 2 === 0 && $rythme === 1);

            if ($conclut) {
                app(ProspectService::class)->moveToStage($prospect, $etapes->firstWhere('is_won', true));
            } elseif ($rythme === 5) {
                app(ProspectService::class)->moveToStage($prospect, $etapes->firstWhere('is_lost', true));
            } elseif ($rythme === 4) {
                // Qualifié sans être conclu : c'est ce cas qui distingue le
                // taux de qualification du taux de conversion.
                app(ProspectService::class)->moveToStage($prospect, $etapes->firstWhere('is_qualified', true));
            }

            $this->repondreAuQuestionnaire($prospect, $index);

            // Prochaine action : sans elle, la tuile « À relancer » de la liste
            // des prospects et sa colonne « Prochaine action » restent vides,
            // alors que c'est la donnée qui dit quoi faire de la journée. Un
            // dossier sur quatre est volontairement en retard.
            if ($index % 2 === 0 && $prospect->converted_at === null && $prospect->lost_at === null) {
                $prospect->forceFill([
                    'next_action_at' => $index % 4 === 0
                        ? now()->subDays(1 + $index % 5)->setTime(9, 0)
                        : now()->addDays(1 + $index % 7)->setTime(14, 0),
                    'next_action_note' => $index % 4 === 0
                        ? 'Relancer, sans réponse au dernier appel.'
                        : 'Envoyer la proposition chiffrée.',
                ])->save();
            }

            // Un prospect sur deux a un rendez-vous. La rotation ci-dessous
            // couvre les quatre statuts *et* laisse plusieurs planifiés à
            // venir : un calendrier dont tous les rendez-vous sont identiques
            // ne permet pas de juger l'écran.
            if ($index % 2 === 1) {
                $this->creerRendezVous($prospect, $index);
            }
        }
    }

    /**
     * Questionnaire de qualification du projet.
     *
     * Sans lui, un seed neuf laisse le constructeur vide et la fiche prospect
     * sur son écran « aucune question » : tout le lot 5 devient indémontrable,
     * et la barre de progression de la qualification n'a rien à mesurer.
     *
     * Les types couverts vont du plus simple au plus structurant — un champ
     * texte, un nombre, une liste fermée, un choix multiple, une case à
     * cocher — parce que c'est la variété, et non le nombre, qui permet de
     * juger le moteur de rendu générique.
     */
    private function creerQuestionnaire(Project $projet): void
    {
        $service = app(QuestionnaireService::class);

        $questions = [
            [
                'type' => 'single_choice',
                'label' => 'Situation familiale',
                'help' => 'Telle que déclarée par le prospect.',
                'required' => true,
                'options' => [
                    ['label' => 'Célibataire'],
                    ['label' => 'Marié'],
                    ['label' => 'Divorcé'],
                    ['label' => 'Veuf'],
                ],
            ],
            [
                'type' => 'number',
                'label' => 'Revenu mensuel (Ar)',
                'required' => true,
            ],
            [
                'type' => 'multiple_choice',
                'label' => 'Garanties souhaitées',
                'required' => false,
                'options' => [
                    ['label' => 'Hospitalisation'],
                    ['label' => 'Optique'],
                    ['label' => 'Dentaire'],
                ],
            ],
            [
                'type' => 'yes_no',
                'label' => 'Déjà couvert par un autre contrat',
                'required' => true,
            ],
            [
                'type' => 'long_text',
                'label' => 'Contexte et besoins exprimés',
                'required' => false,
            ],
        ];

        foreach ($questions as $definition) {
            $service->createQuestion($projet, $definition);
        }
    }

    /**
     * Réponses partielles, et c'est le point.
     *
     * Un questionnaire entièrement rempli et un questionnaire vide donnent
     * deux écrans également inintéressants. Les dossiers laissés incomplets
     * sont ceux qui font apparaître la dominante « réponses obligatoires
     * manquantes » et le repère « à renseigner » sur les champs concernés.
     */
    private function repondreAuQuestionnaire(Prospect $prospect, int $index): void
    {
        $service = app(QuestionnaireService::class);
        $questions = $service->questionsFor($prospect->project)->keyBy('type');

        $famille = $questions->get('single_choice');
        $revenu = $questions->get('number');
        $garanties = $questions->get('multiple_choice');
        $couvert = $questions->get('yes_no');

        $reponses = [];

        if ($famille !== null) {
            $choix = $famille->options->get($index % max(1, $famille->options->count()));
            $reponses[$famille->id] = $choix?->value;
        }

        // Un dossier sur trois laisse le revenu de côté : c'est une question
        // obligatoire, donc celle qui rend la qualification incomplète.
        if ($revenu !== null && $index % 3 !== 1) {
            $reponses[$revenu->id] = 450000 + $index * 125000;
        }

        if ($garanties !== null && $index % 2 === 0) {
            $reponses[$garanties->id] = $garanties->options
                ->take(1 + $index % 3)
                ->pluck('value')
                ->all();
        }

        if ($couvert !== null) {
            $reponses[$couvert->id] = $index % 4 === 0;
        }

        if ($reponses === []) {
            return;
        }

        // Les réponses partielles ne passent pas la validation, qui exige les
        // obligatoires : on écrit donc sans elle, ce que le seeder peut se
        // permettre là où un contrôleur ne le pourrait jamais.
        foreach ($reponses as $questionId => $valeur) {
            ProspectAnswer::query()->updateOrCreate(
                ['prospect_id' => $prospect->id, 'question_id' => $questionId],
                ['company_id' => $prospect->company_id, 'value' => $valeur],
            );
        }
    }

    private function creerRendezVous(Prospect $prospect, int $index): void
    {
        // « Planifié » revient trois fois sur six : une seule occurrence
        // laisserait le choix entre un rendez-vous en retard et un rendez-vous
        // à venir, jamais les deux — et l'une des deux tuiles resterait vide.
        $statuts = [
            AppointmentStatus::Planned,
            AppointmentStatus::Completed,
            AppointmentStatus::Planned,
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
            AppointmentStatus::Planned,
        ];

        $rang = intdiv($index, 2) % count($statuts);
        $statut = $statuts[$rang];

        // Un rendez-vous planifié dont l'heure est passée n'est pas un état
        // incohérent : c'est le cas le plus courant en pratique — il a eu lieu
        // et personne ne l'a clôturé. C'est aussi la tuile dominante du
        // calendrier et du tableau de bord, qui resterait à zéro sans lui.
        // Seul le premier des trois planifiés l'est, les autres restent à venir.
        $enRetard = $rang === 0;

        // Le dernier planifié tombe aujourd'hui : sans lui, la tuile
        // « Rendez-vous du jour » du tableau de bord reste vide quel que soit
        // le jeu de données, et personne ne peut juger l'écran.
        $aujourdhui = $rang === count($statuts) - 1;

        // Hors ces deux cas, les rendez-vous clos sont dans le passé et les
        // planifiés à venir : l'inverse produirait des états incohérents.
        $quand = match (true) {
            $aujourdhui => now()->setTime(16, 0),
            $statut === AppointmentStatus::Planned && ! $enRetard => now()
                ->addDays(1 + $index % 10)->setTime(9 + $index % 8, 0),
            default => now()->subDays(1 + $index % 10)->setTime(9 + $index % 8, 0),
        };

        Appointment::query()->create([
            'company_id' => $prospect->company_id,
            'project_id' => $prospect->project_id,
            'prospect_id' => $prospect->id,
            'user_id' => $prospect->assigned_user_id,
            'scheduled_at' => $quand,
            'duration_minutes' => [30, 45, 60][$index % 3],
            'type' => [AppointmentType::Phone, AppointmentType::Video, AppointmentType::OnSite][$index % 3],
            'status' => $statut,
            'location' => $index % 3 === 2 ? 'Antananarivo, Analakely' : null,
        ]);
    }

    private function creerUtilisateur(int $companyId, string $nom, string $email, UserRole $role, ?int $managerId = null): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'company_id' => $companyId,
                'name' => $nom,
                'password' => Hash::make('password'),
                'role' => $role,
                'manager_id' => $managerId,
                'email_verified_at' => now(),
            ]
        );
    }
}
