<?php

namespace App\Services;

use App\Enums\StageColor;
use App\Models\PipelineStage;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construction et réorganisation des pipelines.
 *
 * Ce service est le seul endroit qui connaît la forme d'un pipeline par
 * défaut. Il ne confère aucune signification aux libellés : ceux-ci ne sont
 * qu'un point de départ que le client renommera, et rien dans le code ne doit
 * en dépendre.
 */
final class PipelineService
{
    /**
     * Pipeline proposé à la création d'un projet — celui de l'esquisse (§5).
     *
     * Un projet sans étape serait inutilisable : aucun prospect ne pourrait y
     * être créé, faute d'étape d'entrée. La création d'un projet en pose donc
     * un d'office, quitte à ce qu'il soit intégralement remanié ensuite.
     *
     * @var list<array{name: string, color: StageColor, is_won?: bool, is_lost?: bool}>
     */
    private const DEFAULT_STAGES = [
        ['name' => 'Nouveau', 'color' => StageColor::Slate],
        ['name' => 'Contacté', 'color' => StageColor::Blue],
        ['name' => 'Qualifié', 'color' => StageColor::Teal],
        ['name' => 'RDV', 'color' => StageColor::Violet],
        ['name' => 'Négociation', 'color' => StageColor::Amber],
        ['name' => 'Converti', 'color' => StageColor::Sage, 'is_won' => true],
        ['name' => 'Perdu', 'color' => StageColor::Red, 'is_lost' => true],
    ];

    public function createDefaultStages(Project $project): void
    {
        $position = 1;

        foreach (self::DEFAULT_STAGES as $stage) {
            PipelineStage::query()->create([
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'name' => $stage['name'],
                'position' => $position++,
                'is_won' => $stage['is_won'] ?? false,
                'is_lost' => $stage['is_lost'] ?? false,
                'color' => $stage['color'],
            ]);
        }
    }

    /**
     * Réordonne les étapes d'un projet.
     *
     * @param  list<int>  $orderedIds  identifiants dans le nouvel ordre
     *
     * @throws ValidationException
     */
    public function reorder(Project $project, array $orderedIds): void
    {
        $existants = $project->stages()->pluck('id')->all();

        sort($existants);
        $fournis = $orderedIds;
        sort($fournis);

        // La liste doit être une permutation exacte : accepter un sous-ensemble
        // laisserait des étapes avec une position obsolète, donc un ordre
        // d'affichage instable et silencieusement faux.
        if ($existants !== $fournis) {
            throw ValidationException::withMessages([
                'stages' => 'La liste doit contenir exactement les étapes du projet, chacune une seule fois.',
            ]);
        }

        DB::transaction(function () use ($project, $orderedIds) {
            // Décalage en deux temps : la contrainte d'unicité sur
            // (project_id, position) rejetterait une réécriture directe, deux
            // étapes se retrouvant momentanément sur la même position.
            $offset = count($orderedIds) + 1000;

            foreach ($orderedIds as $index => $id) {
                PipelineStage::query()
                    ->where('project_id', $project->id)
                    ->whereKey($id)
                    ->update(['position' => $offset + $index]);
            }

            foreach ($orderedIds as $index => $id) {
                PipelineStage::query()
                    ->where('project_id', $project->id)
                    ->whereKey($id)
                    ->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * Position à attribuer à une nouvelle étape : à la fin du pipeline.
     */
    public function nextPosition(Project $project): int
    {
        return (int) $project->stages()->max('position') + 1;
    }
}
