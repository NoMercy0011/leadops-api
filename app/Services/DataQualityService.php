<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Prospect;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Qualité des données d'un projet.
 *
 * La détection de doublons existait déjà, mais **à la création seulement** :
 * elle signale un contact déjà présent au moment où on le saisit. Rien ne la
 * proposait sur un fichier déjà importé — or c'est précisément là qu'elle
 * sert, un import CSV de trois cents lignes étant la première source de
 * doublons.
 *
 * Trois défauts, choisis parce qu'ils faussent chacun un chiffre différent :
 * les doublons gonflent le volume, les prospects injoignables faussent le taux
 * de contact, les qualifications incomplètes faussent le taux de
 * qualification.
 */
class DataQualityService
{
    /** Au-delà, la liste d'exemples est tronquée : elle illustre, elle ne liste pas. */
    private const EXEMPLES_MAX = 5;

    /**
     * @return array{
     *     doublons: array{groupes:int, prospects:int, exemples:list<array{cle:string, type:string, prospects:list<array{id:int, name:string}>}>},
     *     sans_coordonnee: array{total:int, exemples:list<array{id:int, name:string}>},
     *     qualification_incomplete: array{total:int, questions_obligatoires:int, exemples:list<array{id:int, name:string}>},
     * }
     */
    public function forProject(Project $project, User $viewer): array
    {
        return [
            'doublons' => $this->doublons($project, $viewer),
            'sans_coordonnee' => $this->sansCoordonnee($project, $viewer),
            'qualification_incomplete' => $this->qualificationIncomplete($project, $viewer),
        ];
    }

    /**
     * Requête de base, cloisonnée et bornée au périmètre du lecteur.
     *
     * @return Builder<Prospect>
     */
    private function prospects(Project $project, User $viewer)
    {
        // Sans les fiches effacées (revue du lot 9) : elles ne se corrigent
        // plus, et resteraient comptées comme défauts pour toujours.
        return Prospect::query()
            ->visibleTo($viewer)
            ->where('project_id', $project->id)
            ->whereNull('erased_at');
    }

    /**
     * Contacts présents plusieurs fois dans le même projet.
     *
     * Le rapprochement passe par les colonnes normalisées, jamais par la
     * saisie : `+261 34 12 345 67` et `0341234567` sont le même numéro, et
     * comparer les valeurs brutes ne trouverait rien — c'est exactement ce que
     * la décision §10.1 prévoyait en les indexant.
     *
     * Un même contact présent dans **deux projets différents** n'est pas un
     * doublon : c'est la prémisse du multi-projets, et la requête est bornée à
     * un projet pour cette raison.
     *
     * @return array{groupes:int, prospects:int, exemples:list<array{cle:string, type:string, prospects:list<array{id:int, name:string}>}>}
     */
    private function doublons(Project $project, User $viewer): array
    {
        $colonnes = ['phone_normalized' => 'téléphone', 'email_normalized' => 'email'];

        // Les comptes se font en SQL, et seuls les cinq premiers groupes
        // chargent leurs membres (revue du lot 9, contrat §3) : une requête
        // par groupe faisait des centaines d'allers-retours après un import.
        $nombreDeGroupes = 0;
        foreach (array_keys($colonnes) as $colonne) {
            $nombreDeGroupes += DB::query()->fromSub($this->clesEnDouble($project, $viewer, $colonne), 'groupes')->count();
        }

        // Un même prospect peut apparaître dans deux groupes — même numéro
        // avec l'un, même adresse avec l'autre. Le compte porte donc sur les
        // identifiants distincts, sans quoi il dépasserait l'effectif du
        // projet.
        $impliques = (clone $this->prospects($project, $viewer))
            ->where(function ($q) use ($colonnes, $project, $viewer): void {
                foreach (array_keys($colonnes) as $colonne) {
                    $q->orWhereIn($colonne, $this->clesEnDouble($project, $viewer, $colonne));
                }
            })
            ->count();

        $groupes = [];

        foreach ($colonnes as $colonne => $libelle) {
            $restant = self::EXEMPLES_MAX - count($groupes);
            if ($restant <= 0) {
                break;
            }

            $cles = $this->clesEnDouble($project, $viewer, $colonne)
                ->orderBy($colonne)
                ->limit($restant)
                ->pluck($colonne)
                ->all();

            foreach ($cles as $cle) {
                $membres = (clone $this->prospects($project, $viewer))
                    ->where($colonne, $cle)
                    ->orderBy('id')
                    ->get(['id', 'first_name', 'last_name']);

                $groupes[] = [
                    'cle' => (string) $cle,
                    'type' => $libelle,
                    'prospects' => $membres
                        ->map(fn (Prospect $p) => ['id' => $p->id, 'name' => $p->fullName()])
                        ->all(),
                ];
            }
        }

        return [
            'groupes' => $nombreDeGroupes,
            'prospects' => $impliques,
            'exemples' => $groupes,
        ];
    }

    /**
     * Valeurs d'une colonne de rapprochement portées par au moins deux
     * prospects du projet visibles par l'utilisateur.
     *
     * @return Builder<Prospect>
     */
    private function clesEnDouble(Project $project, User $viewer, string $colonne): Builder
    {
        return (clone $this->prospects($project, $viewer))
            ->whereNotNull($colonne)
            ->where($colonne, '!=', '')
            ->groupBy($colonne)
            ->havingRaw('count(*) > 1')
            ->select($colonne);
    }

    /**
     * Prospects sans téléphone ni email.
     *
     * Injoignables, ils restent dans le dénominateur du taux de contact et le
     * tirent vers le bas sans qu'aucune action commerciale n'y change rien.
     *
     * @return array{total:int, exemples:list<array{id:int, name:string}>}
     */
    private function sansCoordonnee(Project $project, User $viewer): array
    {
        $requete = (clone $this->prospects($project, $viewer))
            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''));

        return [
            'total' => (clone $requete)->count(),
            'exemples' => (clone $requete)
                ->orderBy('id')
                ->limit(self::EXEMPLES_MAX)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Prospect $p) => ['id' => $p->id, 'name' => $p->fullName()])
                ->all(),
        ];
    }

    /**
     * Prospects auxquels il manque au moins une réponse obligatoire.
     *
     * Le compte des réponses porte sur les seules questions **obligatoires et
     * actives** : une question supprimée en douceur garde ses réponses mais ne
     * doit plus rien exiger, et une question facultative laissée vide n'est
     * pas un défaut.
     *
     * @return array{total:int, questions_obligatoires:int, exemples:list<array{id:int, name:string}>}
     */
    private function qualificationIncomplete(Project $project, User $viewer): array
    {
        $obligatoires = Question::query()
            ->where('project_id', $project->id)
            ->where('required', true)
            ->pluck('id')
            ->all();

        if ($obligatoires === []) {
            return ['total' => 0, 'questions_obligatoires' => 0, 'exemples' => []];
        }

        $requete = (clone $this->prospects($project, $viewer))
            // Une valeur JSON `null` est une réponse effacée, pas une réponse :
            // la compter laisserait passer un dossier vidé pour complet.
            ->whereRaw(
                '(select count(*) from prospect_answers '.
                'where prospect_answers.prospect_id = prospects.id '.
                'and prospect_answers.question_id in ('.implode(',', array_fill(0, count($obligatoires), '?')).') '.
                "and prospect_answers.value is not null and prospect_answers.value != 'null') < ?",
                [...$obligatoires, count($obligatoires)]
            );

        return [
            'total' => (clone $requete)->count(),
            'questions_obligatoires' => count($obligatoires),
            'exemples' => (clone $requete)
                ->orderBy('id')
                ->limit(self::EXEMPLES_MAX)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Prospect $p) => ['id' => $p->id, 'name' => $p->fullName()])
                ->all(),
        ];
    }
}
