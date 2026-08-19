<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Starter',
            'slug' => 'starter-'.fake()->unique()->numberBetween(1, 99999),
            'max_projects' => 2,
            'max_users' => 5,
            'max_prospects' => 1000,
            'position' => 1,
        ];
    }

    public function business(): static
    {
        return $this->state(fn () => [
            'name' => 'Business',
            'max_projects' => 10,
            'max_users' => 25,
            'max_prospects' => 10000,
            'position' => 2,
        ]);
    }

    /**
     * NULL partout : c'est ainsi que se représente « illimité ».
     */
    public function enterprise(): static
    {
        return $this->state(fn () => [
            'name' => 'Enterprise',
            'max_projects' => null,
            'max_users' => null,
            'max_prospects' => null,
            'position' => 3,
        ]);
    }
}
