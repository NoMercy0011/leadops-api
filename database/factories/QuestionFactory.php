<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

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
            'type' => QuestionType::Text,
            'label' => fake()->sentence(3),
            'required' => false,
            'position' => fake()->unique()->numberBetween(1, 10000),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'company_id' => $project->company_id,
        ]);
    }

    public function ofType(QuestionType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['required' => true]);
    }
}
