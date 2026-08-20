<?php

namespace App\Services;

use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;
use App\Support\ContactNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création et évolution d'un prospect.
 *
 * Trois règles y sont centralisées, parce que chacune doit valoir aussi bien
 * pour la saisie manuelle que pour l'import CSV et la réaffectation en masse :
 * la normalisation des coordonnées, l'horodatage de sortie de parcours, et
 * l'écriture du journal.
 */
final class ProspectService
{
    public function __construct(private readonly ActivityLogger $journal) {}

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function create(Project $project, array $donnees, bool $imported = false): Prospect
    {
        return DB::transaction(function () use ($project, $donnees, $imported) {
            $prospect = new Prospect($donnees);

            $etape = isset($donnees['stage_id'])
                ? $project->stages()->findOrFail($donnees['stage_id'])
                : $this->firstStage($project);

            $prospect->company_id = $project->company_id;
            $prospect->project_id = $project->id;
            $prospect->stage_id = $etape->id;

            // Les jalons sont posés dès la création, et pas seulement au
            // changement d'étape. Un import ou une saisie plaçant d'emblée le
            // prospect à une étape qualifiante le laisserait sinon sans jalon :
            // il apparaîtrait « Qualifié » dans le pipeline sans compter dans
            // le taux de qualification — deux écrans, deux vérités.
            $this->appliquerJalons($prospect, $etape);

            $this->applyNormalizedContact($prospect, $project);

            $prospect->save();

            $this->journal->created($prospect->load('stage'), $imported);

            ReportingService::forgetCache($prospect->company_id);

            return $prospect;
        });
    }

    /**
     * Déplace un prospect vers une autre étape.
     *
     * @throws ValidationException
     */
    public function moveToStage(Prospect $prospect, PipelineStage $stage): Prospect
    {
        // L'étape doit appartenir au projet du prospect. Sans ce contrôle, un
        // identifiant d'un autre projet passerait : le cloisonnement tenant ne
        // l'empêche pas, les deux projets appartenant à la même entreprise.
        if ($stage->project_id !== $prospect->project_id) {
            throw ValidationException::withMessages([
                'stage_id' => "Cette étape n'appartient pas au projet du prospect.",
            ]);
        }

        $ancienne = $prospect->stage;

        if ($ancienne->id === $stage->id) {
            return $prospect;
        }

        return DB::transaction(function () use ($prospect, $ancienne, $stage) {
            $prospect->stage_id = $stage->id;

            $this->appliquerJalons($prospect, $stage);

            $prospect->save();

            $this->journal->stageChanged($prospect, $ancienne, $stage);

            // Le tableau de bord est servi depuis un cache court : sans cette
            // purge, une conversion mettrait cinq minutes à s'y refléter — soit
            // exactement le moment où l'utilisateur va le regarder.
            ReportingService::forgetCache($prospect->company_id);

            return $prospect->fresh(['stage', 'assignedUser']);
        });
    }

    /**
     * Pose les jalons horodatés qu'implique une étape.
     *
     * Point unique, appelé à la création comme au changement d'étape : deux
     * copies de cette logique divergeraient, et un prospect apparaîtrait
     * converti dans le pipeline sans compter dans le taux de conversion.
     *
     * Les trois jalons n'obéissent pas à la même règle, et c'est délibéré :
     *
     * - **Conversion et perte se défont.** Un retour en arrière depuis une
     *   étape terminale rouvre le dossier ; laisser la date en place le ferait
     *   compter comme converti *et* actif dans les rapports.
     * - **La qualification ne se défait jamais.** Un prospect qualifié puis
     *   perdu a bel et bien été qualifié. L'oublier ferait chuter le taux de
     *   qualification à mesure que les dossiers se closent, et l'indicateur
     *   mesurerait le stock restant plutôt que le travail accompli.
     *
     * Aucun jalon n'est repoussé s'il est déjà posé : un aller-retour entre
     * étapes ne doit pas décaler une date de conversion, dont dépend le calcul
     * des durées de cycle.
     */
    private function appliquerJalons(Prospect $prospect, PipelineStage $stage): void
    {
        if ($stage->is_won && $prospect->converted_at === null) {
            $prospect->converted_at = now();
        }

        if ($stage->is_lost && $prospect->lost_at === null) {
            $prospect->lost_at = now();
        }

        if ($stage->is_qualified && $prospect->qualified_at === null) {
            $prospect->qualified_at = now();
        }

        if (! $stage->is_won) {
            $prospect->converted_at = null;
        }

        if (! $stage->is_lost) {
            $prospect->lost_at = null;
        }
    }

    public function assign(Prospect $prospect, ?User $nouveau): Prospect
    {
        $ancien = $prospect->assignedUser;

        if ($ancien?->id === $nouveau?->id) {
            return $prospect;
        }

        return DB::transaction(function () use ($prospect, $ancien, $nouveau) {
            $prospect->assigned_user_id = $nouveau?->id;
            $prospect->save();

            $this->journal->assigned($prospect, $ancien, $nouveau);

            return $prospect->fresh(['stage', 'assignedUser']);
        });
    }

    /**
     * Réaffectation en masse — décision §10.3.
     *
     * @param  Collection<int, Prospect>  $prospects
     * @return int nombre de prospects effectivement réaffectés
     */
    public function bulkAssign(Collection $prospects, ?User $nouveau): int
    {
        $reaffectes = 0;

        DB::transaction(function () use ($prospects, $nouveau, &$reaffectes) {
            foreach ($prospects as $prospect) {
                if ($prospect->assigned_user_id === $nouveau?->id) {
                    continue;
                }

                $ancien = $prospect->assignedUser;

                $prospect->assigned_user_id = $nouveau?->id;
                $prospect->save();

                // Une ligne de journal **par prospect**, jamais une seule pour
                // le lot : l'historique individuel deviendrait faux, et les
                // KPIs de durée de cycle avec lui.
                $this->journal->assigned($prospect, $ancien, $nouveau);

                $reaffectes++;
            }
        });

        return $reaffectes;
    }

    /**
     * Doublons probables dans le projet, à signaler sans bloquer.
     *
     * @return Collection<int, Prospect>
     */
    public function findDuplicates(Project $project, ?string $phone, ?string $email): Collection
    {
        // `company_id` est non nulle sur un projet : la relation est garantie.
        $pays = $project->company->default_country_code;

        return Prospect::query()
            ->potentialDuplicatesOf(
                $project->id,
                ContactNormalizer::phone($phone, $pays),
                ContactNormalizer::email($email),
            )
            ->limit(5)
            ->get();
    }

    /**
     * Recalcule les colonnes de rapprochement depuis les coordonnées saisies.
     *
     * Ces colonnes sont volontairement hors de `$fillable` : elles sont
     * dérivées, jamais fournies par le client. Les rendre assignables en masse
     * permettrait à une requête d'y injecter n'importe quoi, et le
     * rapprochement de doublons deviendrait manipulable de l'extérieur.
     *
     * À appeler après tout changement de `phone` ou `email` — sans quoi la
     * détection continuerait de travailler sur l'ancienne valeur.
     */
    public function applyNormalizedContact(Prospect $prospect, Project $project): void
    {
        // `company_id` est non nulle sur un projet : la relation est garantie.
        $pays = $project->company->default_country_code;

        $prospect->phone_normalized = ContactNormalizer::phone($prospect->phone, $pays);
        $prospect->email_normalized = ContactNormalizer::email($prospect->email);
    }

    /**
     * Première étape du pipeline, celle où entre tout nouveau prospect.
     */
    private function firstStage(Project $project): PipelineStage
    {
        $stage = $project->stages()->orderBy('position')->first();

        if ($stage === null) {
            // Ne devrait pas survenir : la création d'un projet pose un
            // pipeline, et la dernière étape ne peut pas être supprimée.
            throw ValidationException::withMessages([
                'project_id' => "Ce projet n'a aucune étape de pipeline.",
            ]);
        }

        return $stage;
    }
}
