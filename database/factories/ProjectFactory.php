<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'variant_id' => null,
            'name' => 'Projet '.fake()->unique()->words(2, true),
            'product' => fake()->words(3, true),
            'target' => fake()->word(),
            'status' => ProjectStatus::Active,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Suspended]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Completed]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Archived]);
    }
}
