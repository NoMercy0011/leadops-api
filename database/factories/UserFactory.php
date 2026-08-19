<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Commercial,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Le Super Admin est le seul utilisateur sans entreprise : c'est ce
     * company_id nul qui le fait sortir du cloisonnement.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'company_id' => null,
            'role' => UserRole::SuperAdmin,
        ]);
    }

    public function adminClient(): static
    {
        return $this->state(fn () => ['role' => UserRole::AdminClient]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => UserRole::Manager]);
    }

    public function commercial(): static
    {
        return $this->state(fn () => ['role' => UserRole::Commercial]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(fn () => [
            'company_id' => $manager->company_id,
            'manager_id' => $manager->id,
        ]);
    }
}
