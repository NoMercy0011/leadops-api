<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Santé du pipeline d'un projet.
 *
 * La répartition par étape dit où **sont** les prospects. Elle ne dit pas où
 * ils **s'arrêtent** — et c'est la seconde question qui désigne l'étape à
 * travailler. Un pipeline où trente prospects stationnent en « Qualifié »
 * depuis trois semaines et un pipeline où trente prospects viennent d'y entrer
 * donnent exactement la même barre.
 *
 * Les deux mesures se reconstituent depuis `prospect_activities`, qui horodate
 * chaque changement d'étape depuis le lot 4 — c'était précisément l'objet de
 * la mise en garde du plan : « ces données ne pourront pas être reconstituées
 * après coup ».
 */
class PipelineHealthService
{
    /**
     * Au-delà de ce délai sans changement d'étape, un prospect est immobile.
     *
     * Deux semaines : en dessous, le seuil se déclenche sur des dossiers
     * simplement en cours de traitement ; bien au-delà, il ne signale plus rien
     * qu'on ne sache déjà. Ce n'est pas une règle métier du client mais un
     * repère d'affichage, d'où sa place ici plutôt qu'en base.
     */
    private const JOURS_AVANT_IMMOBILITE = 14;

    /**
     * @return array{
     *     stages: list<array{stage_id:int, name:string, color:string, is_won:bool, is_lost:bool, total:int, immobiles:int, duree_moyenne_jours:float|null, passages:int}>,
     *     seuil_immobilite_jours: int,
     * }
     */
    public function forProject(Project $project, User $viewer): array
    {
        $durees = $this->dureesMoyennes($project, $viewer);
        $immobiles = $this->immobiles($project, $viewer);
        $effectifs = $this->effectifs($project, $viewer);

        $stages = $project->stages->map(function ($stage) use ($durees, $immobiles, $effectifs) {
            $duree = $durees[$stage->id] ?? null;

            return [
                'stage_id' => $stage->id,
                'name' => $stage->name,
                'color' => $stage->color->value,
                'is_won' => $stage->is_won,
                'is_lost' => $stage->is_lost,
                'total' => $effectifs[$stage->id] ?? 0,
                // Une étape terminale ne retient personne : un prospect
                // converti n'y « stationne » pas, il y est arrivé.
                'immobiles' => $stage->is_won || $stage->is_lost
                    ? 0
                    : ($immobiles[$stage->id] ?? 0),
                'duree_moyenne_jours' => $duree['jours'] ?? null,
                'passages' => $duree['passages'] ?? 0,
            ];
        })->values()->all();

        return [
            'stages' => $stages,
            'seuil_immobilite_jours' => self::JOURS_AVANT_IMMOBILITE,
        ];
    }

    /**
     * Sous-requête des prospects du projet visibles par cet utilisateur.
     *
     * **Les requêtes brutes qui suivent échappent à CompanyScope**, qui est un
     * global scope Eloquent. Elles partent donc de cette sous-requête, bâtie
     * par l'Eloquent cloisonné, plutôt que d'un `where('project_id', …)` qui
     * franchirait la frontière d'entreprise si l'identifiant venait d'ailleurs.
     *
     * Une sous-requête et non une liste d'identifiants (lot 9, faille F9) : la
     * liste plaçait un paramètre par prospect, et les requêtes préparées
     * natives plafonnent à 65 535 paramètres. Le plan Enterprise est illimité.
     *
     * @return array{0: string, 1: list<mixed>} le SQL et ses paramètres
     */
    private function prospectsVisibles(Project $project, User $viewer): array
    {
        $requete = Prospect::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->id)
            ->select('prospects.id');

        return [$requete->toSql(), $requete->getBindings()];
    }

    /**
     * Effectif courant de chaque étape.
     *
     * @return array<int, int>
     */
    private function effectifs(Project $project, User $viewer): array
    {
        return Prospect::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->id)
            ->selectRaw('stage_id, count(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Durée moyenne passée dans chaque étape, en jours.
     *
     * Chaque ligne de `stage_changed` **clôt** un séjour dans l'étape qu'elle
     * quitte : la durée court du changement précédent — ou de la création du
     * prospect s'il s'agit du premier — jusqu'à cet événement. C'est ce qui
     * permet de mesurer aussi la première étape, alors que l'activité
     * `created` ne conserve que le *nom* de l'étape et non son identifiant.
     *
     * Seuls les séjours **terminés** comptent. Y ajouter le temps des
     * prospects encore présents mêlerait deux grandeurs : le temps qu'il faut
     * pour franchir une étape, et le temps qu'on y attend en ce moment. La
     * seconde est mesurée à part, par le compte d'immobiles.
     *
     * @return array<int, array{jours: float, passages: int}>
     */
    private function dureesMoyennes(Project $project, User $viewer): array
    {
        [$visibles, $parametresVisibles] = $this->prospectsVisibles($project, $viewer);

        // `JSON_VALUE` n'apparaît qu'en projection, jamais en clause `WHERE` :
        // le filtre porte sur `prospect_id` et `type`, tous deux indexés. La
        // mise en garde du §3.5 du plan vise les filtres sur JSON, qui
        // provoqueraient un balayage complet.
        $lignes = DB::table(DB::raw('('.
            'select a.payload, a.occurred_at, p.created_at as cree_le, '.
            'lag(a.occurred_at) over (partition by a.prospect_id order by a.occurred_at) as precedent '.
            'from prospect_activities a '.
            'inner join prospects p on p.id = a.prospect_id '.
            'where a.type = ? and a.prospect_id in ('.$visibles.')'.
            ') as sejours'))
            ->selectRaw("json_value(payload, '$.from_id') as stage_id")
            ->selectRaw('avg(timestampdiff(second, coalesce(precedent, cree_le), occurred_at)) as secondes')
            ->selectRaw('count(*) as passages')
            ->groupBy('stage_id')
            ->setBindings([ActivityType::StageChanged->value, ...$parametresVisibles])
            ->get();

        $resultat = [];

        foreach ($lignes as $ligne) {
            if ($ligne->stage_id === null) {
                continue;
            }

            $resultat[(int) $ligne->stage_id] = [
                'jours' => round(((float) $ligne->secondes) / 86400, 1),
                'passages' => (int) $ligne->passages,
            ];
        }

        return $resultat;
    }

    /**
     * Prospects qui n'ont pas bougé depuis le seuil, par étape.
     *
     * Les dossiers convertis ou perdus sont écartés : ils ne bougent plus par
     * construction, et les compter ferait grossir l'alerte à mesure que le
     * projet réussit.
     *
     * @return array<int, int>
     */
    private function immobiles(Project $project, User $viewer): array
    {
        $limite = Carbon::now()->subDays(self::JOURS_AVANT_IMMOBILITE);

        return Prospect::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->id)
            ->whereNull('converted_at')
            ->whereNull('lost_at')
            ->whereRaw(
                'coalesce((select max(occurred_at) from prospect_activities '.
                'where prospect_id = prospects.id and type = ?), prospects.created_at) < ?',
                [ActivityType::StageChanged->value, $limite]
            )
            ->selectRaw('stage_id, count(*) as total')
            ->groupBy('stage_id')
            ->pluck('total', 'stage_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
