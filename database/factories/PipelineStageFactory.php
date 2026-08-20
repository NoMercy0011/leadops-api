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
            'position' => fake()->unique()->numberBetween(1, 10000),
            'is_won' => false,
            'is_lost' => false,
            'color' => StageColor::Slate,
        ];
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
