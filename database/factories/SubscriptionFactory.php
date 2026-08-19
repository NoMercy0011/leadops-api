<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Suspended]);
    }

    /**
     * Échéance dépassée mais colonne `status` restée à « active » : c'est le
     * cas que `effectiveStatus()` doit rattraper, et il faut pouvoir le tester.
     */
    public function lapsed(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['expires_at' => null]);
    }
}
