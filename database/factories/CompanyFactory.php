<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'status' => CompanyStatus::Active,
            'timezone' => 'Indian/Antananarivo',
            'default_country_code' => 'MG',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Suspended]);
    }
}
