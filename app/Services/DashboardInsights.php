<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;
use App\Support\TimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tendances, série, entonnoir, priorités du jour et classement du tableau de
 * bord — lot 12, contrat `cadrage/contrats/LOT-12_Tableau-de-bord.md`.
 *
 * Conversions et pertes se lisent dans le journal (`stage_changed`, drapeaux
 * `is_won` / `is_lost` du payload), jamais dans `converted_at` : ce jalon
 * s'efface quand un prospect revient en arrière, et la courbe réécrirait le
 * passé. Les jours se découpent en PHP dans le fuseau de l'entreprise, sans
 * `CONVERT_TZ`, dont les tables de fuseaux ne sont pas garanties sur MariaDB.
 *
 * Tout part de `Prospect::visibleTo` : cloisonnement tenant puis rôle.
 */
final class DashboardInsights
{
    public const PERIODES = ['7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * @return array<string, mixed>
     */
    public function for(User $viewer, string $periode, ?int $projetEntonnoir): array
    {
        $fuseau = TimezoneResolver::for($viewer);
        $jours = self::PERIODES[$periode];
        $aujourdhui = TimezoneResolver::now($fuseau)->startOfDay();
        $debut = $aujourdhui->subDays($jours - 1);
        $debutPrecedent = $debut->subDays($jours);

        [$ouverture, $fermeture] = TimezoneResolver::rangeBounds($fuseau, $debut, $aujourdhui);
        [$ouverturePrec, $fermeturePrec] = TimezoneResolver::rangeBounds($fuseau, $debutPrecedent, $debut->subDay());

        $passages = $this->passages($viewer, $ouverturePrec, $fermeture, $fuseau);
        $courants = $passages->filter(fn (array $p) => $p['date'] >= $debut->toDateString());
        $precedents = $passages->filter(fn (array $p) => $p['date'] < $debut->toDateString());

        $nouveaux = $this->nouveaux($viewer, $ouverture, $fermeture);
        $nouveauxPrec = $this->nouveaux($viewer, $ouverturePrec, $fermeturePrec);
        $convertis = $courants->where('won', true)->count();
        $convertisPrec = $precedents->where('won', true)->count();
        $taux = $this->taux($convertis, $nouveaux);
        $tauxPrec = $this->taux($convertisPrec, $nouveauxPrec);

        $granularite = $jours === 90 ? 'week' : 'day';

        $resultat = [
            'period' => ['key' => $periode, 'from' => $debut->toDateString(), 'to' => $aujourdhui->toDateString(), 'granularity' => $granularite],
            'kpis' => [
                'conversion_rate' => [
                    'value' => $taux,
                    'previous' => $tauxPrec,
                    'trend_points' => $taux === null || $tauxPrec === null ? null : round($taux - $tauxPrec, 1),
                ],
                'converted' => $this->kpi($convertis, $convertisPrec),
                'lost' => $this->kpi($courants->where('lost', true)->count(), $precedents->where('lost', true)->count()),
                'new_prospects' => $this->kpi($nouveaux, $nouveauxPrec),
            ],
            'series' => $this->serie($courants, $debut, $aujourdhui, $granularite),
            'funnel' => $this->entonnoir($viewer, $projetEntonnoir, $ouverture, $fermeture),
            'today' => $this->aujourdhui($viewer, $fuseau),
        ];

        // Classement : encadrement seulement (décision n°4 de la planche). La
        // clé est absente pour un commercial, pas vide.
        if (in_array($viewer->role, [UserRole::AdminClient, UserRole::Manager], true)) {
            $resultat['leaderboard'] = $this->classement($viewer, $courants, $aujourdhui, $ouverture, $fermeture);
        }

        return $resultat;
    }

    /**
     * Passages à une étape gagnée ou perdue, dédoublonnés par prospect et par
     * jour local.
     *
     * @return Collection<int, array{prospect_id: int, date: string, won: bool, lost: bool}>
     */
    private function passages(User $viewer, CarbonImmutable $de, CarbonImmutable $a, string $fuseau): Collection
    {
        $lignes = ProspectActivity::query()
            ->where('type', ActivityType::StageChanged->value)
            ->whereBetween('occurred_at', [$de, $a])
            ->whereIn('prospect_id', Prospect::query()->visibleTo($viewer)->select('prospects.id'))
            // MariaDB 10.4 rend un booléen JSON en « 1 » par JSON_VALUE, les
            // versions récentes en « true » : les deux formes sont acceptées.
            ->where(fn (Builder $q) => $q
                ->whereRaw("JSON_VALUE(payload, '$.is_won') IN ('true', '1')")
                ->orWhereRaw("JSON_VALUE(payload, '$.is_lost') IN ('true', '1')"))
            ->get(['prospect_id', 'occurred_at', 'payload']);

        return $lignes
            ->map(fn (ProspectActivity $l) => [
                'prospect_id' => (int) $l->prospect_id,
                'date' => CarbonImmutable::parse($l->occurred_at)->setTimezone($fuseau)->toDateString(),
                'won' => (bool) ($l->payload['is_won'] ?? false),
                'lost' => (bool) ($l->payload['is_lost'] ?? false),
            ])
            ->unique(fn (array $p) => $p['prospect_id'].'|'.$p['date'].'|'.($p['won'] ? 'w' : 'l'))
            ->values();
    }

    private function nouveaux(User $viewer, CarbonImmutable $de, CarbonImmutable $a): int
    {
        return Prospect::query()->visibleTo($viewer)->whereBetween('prospects.created_at', [$de, $a])->count();
    }

    private function taux(int $numerateur, int $denominateur): ?float
    {
        return $denominateur === 0 ? null : round($numerateur * 100 / $denominateur, 1);
    }

    /**
     * @return array{value: int, previous: int, trend: float|null}
     */
    private function kpi(int $valeur, int $precedent): array
    {
        return [
            'value' => $valeur,
            'previous' => $precedent,
            // Sans base, pas de pourcentage inventé : « — » à l'écran.
            'trend' => $precedent === 0 ? null : round(($valeur - $precedent) * 100 / $precedent, 1),
        ];
    }

    /**
     * Une entrée par jour, ou par semaine commençant le lundi (H-1), jours
     * vides compris.
     *
     * @param  Collection<int, array{prospect_id: int, date: string, won: bool, lost: bool}>  $passages
     * @return list<array{date: string, converted: int, lost: int}>
     */
    private function serie(Collection $passages, CarbonImmutable $debut, CarbonImmutable $fin, string $granularite): array
    {
        $cle = fn (string $date) => $granularite === 'week'
            ? CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString()
            : $date;

        $points = [];
        for ($jour = $debut; $jour->lte($fin); $jour = $jour->addDay()) {
            $points[$cle($jour->toDateString())] ??= ['date' => $cle($jour->toDateString()), 'converted' => 0, 'lost' => 0];
        }
        foreach ($passages as $p) {
            $points[$cle($p['date'])][$p['won'] ? 'converted' : 'lost']++;
        }

        return array_values($points);
    }

    /**
     * Prospects créés dans la période, par étape la plus avancée atteinte.
     * Une étape perdue n'entre pas dans l'entonnoir ; un saut d'étape compte
     * les étapes franchies (l'entonnoir reste décroissant).
     *
     * @return array<string, mixed>|null
     */
    private function entonnoir(User $viewer, ?int $demande, CarbonImmutable $de, CarbonImmutable $a): ?array
    {
        $projets = Project::query()->visibleTo($viewer)
            ->where('status', ProjectStatus::Active->value)
            ->orderBy('name')->get(['id', 'name']);

        // Un projet hors périmètre est ignoré, sans erreur : répondre
        // autrement confirmerait son existence.
        $projet = $projets->firstWhere('id', $demande) ?? $projets->first();
        if ($projet === null) {
            return null;
        }

        $etapes = $projet->stages()->where('is_lost', false)->orderBy('position')->get(['id', 'name', 'position', 'is_won', 'is_lost']);
        $rang = $etapes->pluck('position', 'id');
        $premiere = (int) $etapes->min('position');

        $prospects = Prospect::query()->visibleTo($viewer)
            ->where('project_id', $projet->id)
            ->whereBetween('prospects.created_at', [$de, $a])
            ->get(['prospects.id', 'stage_id'])
            // Un prospect à une étape perdue (hors entonnoir) part de la première
            // étape ; son historique dira jusqu'où il est allé.
            ->mapWithKeys(fn (Prospect $p) => [$p->id => (int) ($rang[$p->stage_id] ?? $premiere)]);

        $atteint = $prospects->all();
        if ($atteint !== []) {
            ProspectActivity::query()
                ->where('type', ActivityType::StageChanged->value)
                ->whereIn('prospect_id', array_keys($atteint))
                ->get(['prospect_id', 'payload'])
                ->each(function (ProspectActivity $l) use (&$atteint, $rang) {
                    $vers = $rang[$l->payload['to_id'] ?? 0] ?? null;
                    if ($vers !== null && $vers > $atteint[$l->prospect_id]) {
                        $atteint[$l->prospect_id] = (int) $vers;
                    }
                });
        }

        $precedent = null;
        $lignes = [];
        foreach ($etapes as $etape) {
            $n = count(array_filter($atteint, fn (int $pos) => $pos >= $etape->position));
            $lignes[] = [
                'stage_id' => $etape->id,
                'name' => $etape->name,
                'reached' => $n,
                'pass_rate' => $precedent === null ? null : $this->taux($n, $precedent),
                'is_won' => (bool) $etape->is_won,
                'is_lost' => false,
            ];
            $precedent = $n;
        }

        return [
            'project' => ['id' => $projet->id, 'name' => $projet->name],
            'projects' => $projets->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'stages' => $lignes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aujourdhui(User $viewer, string $fuseau): array
    {
        [$ouverture, $fermeture] = TimezoneResolver::dayBounds($fuseau);
        $planifies = fn () => Appointment::query()->visibleTo($viewer)->ofActiveProspects()
            ->where('status', AppointmentStatus::Planned->value);

        $enRetard = $planifies()->where('scheduled_at', '<', now());
        $plusAncien = (clone $enRetard)->min('scheduled_at');

        $duJour = Appointment::query()->visibleTo($viewer)->ofActiveProspects()
            ->between($ouverture, $fermeture)
            ->with(['prospect', 'project:id,name'])
            ->orderBy('scheduled_at');

        $limite = now()->subDays(PipelineHealthService::JOURS_AVANT_IMMOBILITE);

        return [
            'overdue' => [
                'count' => $enRetard->count(),
                'oldest' => $plusAncien === null ? null : CarbonImmutable::parse($plusAncien)->setTimezone($fuseau)->toDateString(),
            ],
            'appointments' => [
                'count' => (clone $duJour)->count(),
                'items' => $duJour->limit(3)->get()->map(fn (Appointment $r) => [
                    'id' => $r->id,
                    'scheduled_at' => $r->scheduled_at->toIso8601String(),
                    'type' => $r->type->value,
                    'prospect' => ['id' => $r->prospect_id, 'name' => $r->prospect->fullName()],
                    'project' => ['id' => $r->project_id, 'name' => $r->project->name],
                ])->all(),
            ],
            // Même définition que la santé du pipeline : aucun changement
            // d'étape depuis le seuil, dossiers terminés écartés.
            'stale' => [
                'count' => Prospect::query()->visibleTo($viewer)
                    ->whereNull('converted_at')->whereNull('lost_at')
                    ->whereRaw(
                        'coalesce((select max(occurred_at) from prospect_activities '.
                        'where prospect_id = prospects.id and type = ?), prospects.created_at) < ?',
                        [ActivityType::StageChanged->value, $limite]
                    )->count(),
                'days' => PipelineHealthService::JOURS_AVANT_IMMOBILITE,
            ],
        ];
    }

    /**
     * Conversion attribuée au commercial affecté aujourd'hui au prospect
     * (contrat §1) : l'auteur du geste, dans le journal, peut être un manager.
     *
     * @param  Collection<int, array{prospect_id: int, date: string, won: bool, lost: bool}>  $passages
     * @return list<array<string, mixed>>
     */
    private function classement(User $viewer, Collection $passages, CarbonImmutable $aujourdhui, CarbonImmutable $de, CarbonImmutable $a): array
    {
        $commerciaux = User::query()->visibleTo($viewer)
            ->where('role', UserRole::Commercial->value)
            ->orderBy('name')->get(['id', 'name']);
        if ($commerciaux->isEmpty()) {
            return [];
        }

        $gains = $passages->where('won', true);
        $affectes = Prospect::query()->whereIn('id', $gains->pluck('prospect_id')->unique()->all())
            ->pluck('assigned_user_id', 'id');

        $nouveaux = Prospect::query()->visibleTo($viewer)
            ->whereBetween('prospects.created_at', [$de, $a])
            ->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, count(*) as total')->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        $rdv = Appointment::query()->visibleTo($viewer)
            ->whereBetween('appointments.scheduled_at', [$de, $a])
            ->join('prospects', 'appointments.prospect_id', '=', 'prospects.id')
            ->selectRaw('prospects.assigned_user_id as uid, count(*) as total')
            ->groupBy('prospects.assigned_user_id')
            ->pluck('total', 'uid');

        $sept = collect(range(6, 0))->map(fn (int $n) => $aujourdhui->subDays($n)->toDateString());

        return $commerciaux->map(function (User $u) use ($gains, $affectes, $nouveaux, $rdv, $sept) {
            $siens = $gains->filter(fn (array $p) => ($affectes[$p['prospect_id']] ?? null) === $u->id);

            return [
                'user' => ['id' => $u->id, 'name' => $u->name],
                'converted' => $siens->count(),
                'conversion_rate' => $this->taux($siens->count(), (int) ($nouveaux[$u->id] ?? 0)),
                'appointments' => (int) ($rdv[$u->id] ?? 0),
                'last_7_days' => $sept->map(fn (string $d) => $siens->where('date', $d)->count())->all(),
            ];
        })
            ->sortBy([['converted', 'desc'], ['user.name', 'asc']])
            ->values()->all();
    }
}
