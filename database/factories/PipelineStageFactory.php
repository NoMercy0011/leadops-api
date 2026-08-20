<?php

namespace Database\Factories;

use App\Enums\StageColor;
use App\Models\PipelineStage;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'company_id' => fn (array $attributs) => Project::query()
                ->acrossAllCompanies()
                ->whereKey($attributs['project_id'])
                ->value('company_id'),
            'name' => fake()->unique()->word(),
            // `position` est posée par `configure()`, qui seul sait tenir le
            // compte à l'intérieur d'un lot.
            'is_won' => false,
            'is_lost' => false,
            'color' => StageColor::Slate,
        ];
    }

    /**
     * Attribue une position séquentielle, comme le fait PipelineService en
     * production.
     *
     * Même raison que pour QuestionFactory : un tirage aléatoire satisfait la
     * contrainte d'unicité mais rend l'ordre du pipeline imprévisible. Le
     * compteur de lot est nécessaire parce que `count(N)->create()` construit
     * toutes les instances avant d'en persister aucune.
     */
    public function configure(): static
    {
        $dernieres = [];

        return $this->afterMaking(function (PipelineStage $stage) use (&$dernieres): void {
            $projet = (int) $stage->project_id;

            $enBase = (int) PipelineStage::query()
                ->acrossAllCompanies()
                ->where('project_id', $projet)
                ->max('position');

            $suivante = max($enBase, $dernieres[$projet] ?? 0) + 1;

            $dernieres[$projet] = $suivante;
            $stage->position = $suivante;
        });
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'company_id' => $project->company_id,
        ]);
    }

    public function won(): static
    {
        return $this->state(fn () => ['is_won' => true, 'color' => StageColor::forWon()]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['is_lost' => true, 'color' => StageColor::forLost()]);
    }
}
