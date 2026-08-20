<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;
use App\Support\TimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agrégats du tableau de bord (§2) et des rapports (§11).
 *
 * Deux règles gouvernent cette classe.
 *
 * **Aucun libellé d'étape n'est testé.** Les taux se calculent sur des jalons
 * horodatés portés par le prospect — `qualified_at`, `converted_at`,
 * `lost_at` — eux-mêmes posés à partir des drapeaux de l'étape. Un client qui
 * renomme « Converti » en « Signé » ne casse rien.
 *
 * **Un nombre fixe de requêtes, quel que soit le nombre de projets.** Un
 * dashboard naïf émet une requête par projet et s'effondre au dixième. Chaque
 * répartition passe donc par un `GROUP BY` unique.
 */
final class ReportingService
{
    /**
     * Filtres du §11 de l'esquisse.
     *
     * @param  array{project_id?: int, variant_id?: int, user_id?: int, from?: string, to?: string}  $filtres
     * @return array<string, mixed>
     */
    public function report(User $viewer, array $filtres = []): array
    {
        $fuseau = TimezoneResolver::for($viewer);
        [$debut, $fin] = $this->periode($fuseau, $filtres);

        $base = fn () => $this->prospectsQuery($viewer, $filtres, $debut, $fin);

        // Cinq décomptes en cinq requêtes indexées. Les charger en mémoire pour
        // les compter en PHP ferait grimper l'empreinte avec la volumétrie,
        // qui est illimitée sur un plan Enterprise.
        $total = $base()->count();
        $contactes = $base()->whereHas('activities', fn (Builder $q) => $q->whereIn('type', $this->typesInteraction()))->count();
        $qualifies = $base()->whereNotNull('qualified_at')->count();
        $convertis = $base()->whereNotNull('converted_at')->count();
        $perdus = $base()->whereNotNull('lost_at')->count();

        return [
            'period' => [
                'from' => $debut->toIso8601String(),
                'to' => $fin->toIso8601String(),
                'timezone' => $fuseau,
            ],
            'totals' => [
                'prospects' => $total,
                'contacted' => $contactes,
                'qualified' => $qualifies,
                'converted' => $convertis,
                'lost' => $perdus,
                'appointments' => $this->appointmentsQuery($viewer, $filtres, $debut, $fin)->count(),
            ],
            'rates' => [
                // Le dénominateur est le nombre de prospects de la période, pas
                // le stock total : un rapport mensuel doit mesurer le travail du
                // mois, pas la performance cumulée depuis l'origine.
                'qualification' => $this->taux($qualifies, $total),
                'conversion' => $this->taux($convertis, $total),
                'loss' => $this->taux($perdus, $total),
            ],
            'by_project' => $this->parProjet($viewer, $filtres, $debut, $fin),
            'by_user' => $this->parCommercial($viewer, $filtres, $debut, $fin),
        ];
    }

    /**
     * Indicateurs du §2 de l'esquisse.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $viewer): array
    {
        $fuseau = TimezoneResolver::for($viewer);
        [$ouverture, $fermeture] = TimezoneResolver::dayBounds($fuseau);

        $prospects = fn () => Prospect::query()->visibleTo($viewer);

        return [
            'timezone' => $fuseau,
            'active_projects' => Project::query()
                ->visibleTo($viewer)
                ->where('status', ProjectStatus::Active->value)
                ->count(),
            'prospects' => [
                'total' => $prospects()->count(),
                'converted' => $prospects()->whereNotNull('converted_at')->count(),
                'lost' => $prospects()->whereNotNull('lost_at')->count(),
            ],
            'conversion_rate' => $this->taux(
                $prospects()->whereNotNull('converted_at')->count(),
                $prospects()->count(),
            ),
            'appointments' => [
                // « Du jour » désigne la journée locale du client, bornée par
                // TimezoneResolver : un filtre sur la date UTC amputerait la
                // journée aux deux extrémités sans que rien ne le signale.
                'today' => Appointment::query()
                    ->visibleTo($viewer)
                    ->between($ouverture, $fermeture)
                    ->count(),
                'upcoming' => Appointment::query()
                    ->visibleTo($viewer)
                    ->where('scheduled_at', '>', $fermeture)
                    ->where('status', AppointmentStatus::Planned->value)
                    ->count(),
                'overdue' => Appointment::query()
                    ->visibleTo($viewer)
                    ->where('scheduled_at', '<', now())
                    ->where('status', AppointmentStatus::Planned->value)
                    ->count(),
            ],
            'by_stage' => $this->parEtape($viewer),
            'by_project' => $this->parProjet($viewer, [], null, null),
        ];
    }

    /**
     * Répartition des prospects par étape, tous projets confondus.
     *
     * Une seule requête groupée, jointe aux étapes pour récupérer libellé et
     * couleur. Interroger projet par projet multiplierait les allers-retours
     * par le nombre de projets.
     *
     * @return list<array<string, mixed>>
     */
    private function parEtape(User $viewer): array
    {
        return Prospect::query()
            ->visibleTo($viewer)
            ->join('pipeline_stages', 'prospects.stage_id', '=', 'pipeline_stages.id')
            ->select([
                'pipeline_stages.id as stage_id',
                'pipeline_stages.name as stage',
                'pipeline_stages.color as color',
                'pipeline_stages.position as position',
                'pipeline_stages.is_won as is_won',
                'pipeline_stages.is_lost as is_lost',
                DB::raw('COUNT(prospects.id) as total'),
            ])
            ->groupBy(
                'pipeline_stages.id', 'pipeline_stages.name', 'pipeline_stages.color',
                'pipeline_stages.position', 'pipeline_stages.is_won', 'pipeline_stages.is_lost',
            )
            ->orderBy('pipeline_stages.position')
            // `toBase` : ces lignes ne sont pas des prospects mais des
            // agrégats joints. Les hydrater en modèles serait à la fois plus
            // coûteux et mensonger — les propriétés lues n'existent sur aucun
            // Prospect.
            ->toBase()
            ->get()
            ->map(fn (object $ligne) => [
                'stage_id' => (int) $ligne->stage_id,
                'stage' => $ligne->stage,
                'color' => $ligne->color,
                'is_won' => (bool) $ligne->is_won,
                'is_lost' => (bool) $ligne->is_lost,
                'total' => (int) $ligne->total,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return list<array<string, mixed>>
     */
    private function parProjet(User $viewer, array $filtres, ?CarbonImmutable $debut, ?CarbonImmutable $fin): array
    {
        return $this->prospectsQuery($viewer, $filtres, $debut, $fin)
            ->join('projects', 'prospects.project_id', '=', 'projects.id')
            ->leftJoin('variants', 'projects.variant_id', '=', 'variants.id')
            ->select([
                'projects.id as project_id',
                'projects.name as project',
                'variants.name as variant',
                DB::raw('COUNT(prospects.id) as total'),
                DB::raw('SUM(CASE WHEN prospects.qualified_at IS NOT NULL THEN 1 ELSE 0 END) as qualified'),
                DB::raw('SUM(CASE WHEN prospects.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted'),
                DB::raw('SUM(CASE WHEN prospects.lost_at IS NOT NULL THEN 1 ELSE 0 END) as lost'),
            ])
            ->groupBy('projects.id', 'projects.name', 'variants.name')
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn (object $ligne) => $this->ligneAvecTaux($ligne, [
                'project_id' => (int) $ligne->project_id,
                'project' => $ligne->project,
                // L'axe « variante » du §11 : il vient de la table de
                // référence, jamais d'une saisie libre — décision §10.4.
                'variant' => $ligne->variant,
            ]))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return list<array<string, mixed>>
     */
    private function parCommercial(User $viewer, array $filtres, ?CarbonImmutable $debut, ?CarbonImmutable $fin): array
    {
        return $this->prospectsQuery($viewer, $filtres, $debut, $fin)
            ->leftJoin('users', 'prospects.assigned_user_id', '=', 'users.id')
            ->select([
                'users.id as user_id',
                'users.name as user',
                DB::raw('COUNT(prospects.id) as total'),
                DB::raw('SUM(CASE WHEN prospects.qualified_at IS NOT NULL THEN 1 ELSE 0 END) as qualified'),
                DB::raw('SUM(CASE WHEN prospects.converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted'),
                DB::raw('SUM(CASE WHEN prospects.lost_at IS NOT NULL THEN 1 ELSE 0 END) as lost'),
            ])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->toBase()
            ->get()
            ->map(fn (object $ligne) => $this->ligneAvecTaux($ligne, [
                'user_id' => $ligne->user_id === null ? null : (int) $ligne->user_id,
                // `leftJoin` : les prospects non affectés forment une ligne à
                // part entière. Les écarter ferait mentir le total.
                'user' => $ligne->user ?? 'Non affectés',
            ]))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entete
     * @return array<string, mixed>
     */
    private function ligneAvecTaux(object $ligne, array $entete): array
    {
        $total = (int) $ligne->total;

        return [
            ...$entete,
            'total' => $total,
            'qualified' => (int) $ligne->qualified,
            'converted' => (int) $ligne->converted,
            'lost' => (int) $ligne->lost,
            'qualification_rate' => $this->taux((int) $ligne->qualified, $total),
            'conversion_rate' => $this->taux((int) $ligne->converted, $total),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return Builder<Prospect>
     */
    private function prospectsQuery(User $viewer, array $filtres, ?CarbonImmutable $debut, ?CarbonImmutable $fin): Builder
    {
        return Prospect::query()
            ->visibleTo($viewer)
            ->when(isset($filtres['project_id']), fn ($q) => $q->where('prospects.project_id', $filtres['project_id']))
            ->when(isset($filtres['user_id']), fn ($q) => $q->where('prospects.assigned_user_id', $filtres['user_id']))
            ->when(
                isset($filtres['variant_id']),
                fn ($q) => $q->whereIn(
                    'prospects.project_id',
                    Project::query()->where('variant_id', $filtres['variant_id'])->select('id')
                )
            )
            ->when(
                $debut !== null && $fin !== null,
                // La période porte sur la date de création : un prospect
                // converti en août mais créé en juin appartient au rapport de
                // juin. Mesurer autrement mélangerait deux cohortes.
                fn ($q) => $q->whereBetween('prospects.created_at', [$debut, $fin])
            );
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return Builder<Appointment>
     */
    private function appointmentsQuery(User $viewer, array $filtres, ?CarbonImmutable $debut, ?CarbonImmutable $fin): Builder
    {
        return Appointment::query()
            ->visibleTo($viewer)
            ->when(isset($filtres['project_id']), fn ($q) => $q->where('project_id', $filtres['project_id']))
            ->when(isset($filtres['user_id']), fn ($q) => $q->where('user_id', $filtres['user_id']))
            ->when(
                $debut !== null && $fin !== null,
                fn ($q) => $q->whereBetween('scheduled_at', [$debut, $fin])
            );
    }

    /**
     * Bornes UTC de la période demandée, calculées en journées locales.
     *
     * @param  array<string, mixed>  $filtres
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function periode(string $fuseau, array $filtres): array
    {
        $fin = isset($filtres['to'])
            ? CarbonImmutable::parse($filtres['to'])
            : TimezoneResolver::now($fuseau);

        // Trente jours par défaut : une période ouverte ramènerait tout
        // l'historique et rendrait les taux incomparables d'un mois à l'autre.
        $debut = isset($filtres['from'])
            ? CarbonImmutable::parse($filtres['from'])
            : $fin->subDays(30);

        return TimezoneResolver::rangeBounds($fuseau, $debut, $fin);
    }

    /**
     * Taux en pourcentage, arrondi au dixième.
     *
     * Renvoie `null` et non zéro sur un dénominateur nul : « aucun prospect »
     * n'est pas « 0 % de conversion », et afficher zéro laisserait croire à un
     * échec là où il n'y a simplement rien à mesurer.
     */
    private function taux(int $numerateur, int $denominateur): ?float
    {
        return $denominateur === 0
            ? null
            : round($numerateur * 100 / $denominateur, 1);
    }

    /**
     * Types d'activité comptant comme une interaction commerciale.
     *
     * @return list<string>
     */
    private function typesInteraction(): array
    {
        return collect(ActivityType::cases())
            ->filter(fn ($type) => $type->isInteraction())
            ->map(fn ($type) => $type->value)
            ->values()
            ->all();
    }

    /**
     * Tableau de bord, servi depuis un cache court.
     *
     * **La clé porte l'identifiant de l'utilisateur, pas celui de l'entreprise.**
     * Le contenu dépend du rôle : un manager voit son équipe, un commercial ses
     * seuls prospects. Une clé par entreprise servirait la vue du premier
     * arrivé à tous les suivants — une fuite de périmètre, pas un simple défaut
     * d'affichage.
     *
     * L'invalidation passe par un numéro de version propre à l'entreprise,
     * incrémenté à chaque événement qui change les chiffres. Purger les clés
     * une par une supposerait de connaître tous les utilisateurs connectés ;
     * changer la version les périme toutes d'un coup.
     *
     * @return array<string, mixed>
     */
    public function cachedDashboard(User $viewer): array
    {
        $version = self::version($viewer->company_id);

        return cache()->remember(
            "dashboard:v{$version}:u{$viewer->id}",
            // Cinq minutes : au-delà, un commercial qui vient de convertir un
            // prospect ne verrait pas son geste se refléter, et douterait de
            // l'outil plutôt que d'attendre.
            now()->addMinutes(5),
            fn () => $this->dashboard($viewer),
        );
    }

    /**
     * Numéro de version courant, amorcé si la clé n'existe pas encore.
     *
     * L'amorçage n'est pas cosmétique : `increment` sur une clé absente la crée
     * à 1. Si 1 était aussi la valeur par défaut de lecture, la toute première
     * invalidation ne changerait donc rien — le cache survivrait à la première
     * conversion, exactement le cas qu'on cherche à couvrir.
     */
    private static function version(?int $companyId): int
    {
        $cle = self::versionKey($companyId);
        $valeur = cache()->get($cle);

        if ($valeur === null) {
            cache()->forever($cle, 1);

            return 1;
        }

        return (int) $valeur;
    }

    private static function versionKey(?int $companyId): string
    {
        return 'dashboard-version:'.($companyId ?? 'platform');
    }

    /**
     * Périme le tableau de bord de toute une entreprise.
     *
     * Appelé sur les événements qui déplacent les chiffres — changement
     * d'étape, rendez-vous. Sans cela les KPIs resteraient figés cinq minutes
     * après une conversion, ce qui est précisément le moment où l'utilisateur
     * les regarde.
     */
    public static function forgetCache(Company|int|null $company): void
    {
        $id = $company instanceof Company ? $company->id : $company;

        // L'amorçage passe d'abord : `increment` est atomique, mais seulement
        // sur une clé qui existe déjà.
        self::version($id);

        cache()->increment(self::versionKey($id));
    }
}
