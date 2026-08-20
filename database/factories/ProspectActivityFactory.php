<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProspectActivity>
 */
class ProspectActivityFactory extends Factory
{
    protected $model = ProspectActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prospect_id' => Prospect::factory(),
            'company_id' => fn (array $attributs) => Prospect::query()
                ->acrossAllCompanies()
                ->whereKey($attributs['prospect_id'])
                ->value('company_id'),
            'user_id' => null,
            'type' => ActivityType::NoteAdded,
            'payload' => ['note' => fake()->sentence()],
            'occurred_at' => now(),
        ];
    }
}
