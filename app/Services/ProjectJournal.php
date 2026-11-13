<?php

namespace App\Services;

use App\Enums\ProjectActivityType;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Question;
use Illuminate\Support\Facades\Auth;

/**
 * Écriture du journal de configuration d'un projet.
 *
 * Point d'entrée unique, sur le modèle d'`ActivityLogger` : les contrôleurs
 * appellent une méthode nommée par l'événement, jamais `ProjectActivity::create`
 * directement. Sans cela, chaque appelant invente sa propre forme de payload et
 * l'écran de lecture ne sait plus quoi afficher.
 *
 * Les libellés sont conservés **en clair** dans le payload, jamais seulement
 * leurs identifiants : une étape renommée ou une question supprimée rendrait
 * l'historique illisible s'il fallait les résoudre a posteriori — c'est
 * exactement la raison d'être de ce journal.
 */
class ProjectJournal
{
    public function projectCreated(Project $project): void
    {
        $this->write($project, ProjectActivityType::ProjectCreated, [
            'name' => $project->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     */
    public function projectUpdated(Project $project, array $avant, array $apres): void
    {
        // Seuls les champs réellement modifiés sont conservés : enregistrer
        // l'objet entier à chaque enregistrement noierait le changement utile
        // dans une liste de valeurs identiques.
        $changements = [];

        foreach ($apres as $champ => $valeur) {
            if (($avant[$champ] ?? null) !== $valeur) {
                $changements[$champ] = ['de' => $avant[$champ] ?? null, 'vers' => $valeur];
            }
        }

        if ($changements === []) {
            return;
        }

        $this->write($project, ProjectActivityType::ProjectUpdated, ['champs' => $changements]);
    }

    public function statusChanged(Project $project, string $ancien, string $nouveau): void
    {
        $this->write($project, ProjectActivityType::StatusChanged, [
            'de' => $ancien,
            'vers' => $nouveau,
        ]);
    }

    public function stageAdded(PipelineStage $stage): void
    {
        $this->write($stage->project, ProjectActivityType::StageAdded, [
            'stage' => $stage->name,
        ]);
    }

    /**
     * Renommage d'une étape.
     *
     * Rien n'est écrit si le nom n'a pas bougé. Une modification de couleur ou
     * de terminalité passe par le même point d'entrée, et journaliser
     * « de Qualifié vers Qualifié » remplirait l'historique de lignes qui ne
     * disent rien — au point de noyer les renommages, qui sont précisément ce
     * que ce journal existe pour tracer.
     */
    public function stageUpdated(PipelineStage $stage, string $ancienNom): void
    {
        if ($stage->name === $ancienNom) {
            return;
        }

        $this->write($stage->project, ProjectActivityType::StageUpdated, [
            'de' => $ancienNom,
            'vers' => $stage->name,
        ]);
    }

    public function stageRemoved(Project $project, string $nom, ?string $repli): void
    {
        $this->write($project, ProjectActivityType::StageRemoved, [
            'stage' => $nom,
            'repli' => $repli,
        ]);
    }

    public function stagesReordered(Project $project): void
    {
        $this->write($project, ProjectActivityType::StagesReordered, [
            'ordre' => $project->stages()->orderBy('position')->pluck('name')->all(),
        ]);
    }

    public function questionAdded(Question $question): void
    {
        $this->write($question->project, ProjectActivityType::QuestionAdded, [
            'question' => $question->label,
            'type' => $question->type->value,
        ]);
    }

    /** Même retenue que pour les étapes : un libellé inchangé n'est pas un événement. */
    public function questionUpdated(Question $question, string $ancienLibelle): void
    {
        if ($question->label === $ancienLibelle) {
            return;
        }

        $this->write($question->project, ProjectActivityType::QuestionUpdated, [
            'de' => $ancienLibelle,
            'vers' => $question->label,
        ]);
    }

    public function questionRemoved(Question $question): void
    {
        $this->write($question->project, ProjectActivityType::QuestionRemoved, [
            'question' => $question->label,
        ]);
    }

    /**
     * @param  list<string>  $avant
     * @param  list<string>  $apres
     */
    public function membersChanged(Project $project, array $avant, array $apres): void
    {
        $entrants = array_values(array_diff($apres, $avant));
        $sortants = array_values(array_diff($avant, $apres));

        if ($entrants === [] && $sortants === []) {
            return;
        }

        $this->write($project, ProjectActivityType::MembersChanged, [
            'entrants' => $entrants,
            'sortants' => $sortants,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(Project $project, ProjectActivityType $type, array $payload = []): void
    {
        ProjectActivity::query()->create([
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            // Nul pour un seeder ou une commande console : le journal reste
            // écrit, sans auteur.
            'user_id' => Auth::id(),
            'type' => $type,
            'payload' => array_filter($payload, fn ($valeur) => $valeur !== null),
            'occurred_at' => now(),
        ]);
    }
}
