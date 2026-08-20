<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

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
            // Le projet vient du prospect, comme en production : les laisser
            // diverger produirait des fixtures incohérentes qui masqueraient
            // de vraies régressions.
            'project_id' => fn (array $attributs) => Prospect::query()
                ->acrossAllCompanies()
                ->whereKey($attributs['prospect_id'])
                ->value('project_id'),
            'user_id' => null,
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'duration_minutes' => 60,
            'type' => AppointmentType::Phone,
            'status' => AppointmentStatus::Planned,
        ];
    }

    public function forProspect(Prospect $prospect): static
    {
        return $this->state(fn () => [
            'prospect_id' => $prospect->id,
            'company_id' => $prospect->company_id,
            'project_id' => $prospect->project_id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function at(string $quand): static
    {
        return $this->state(fn () => ['scheduled_at' => $quand]);
    }

    public function withStatus(AppointmentStatus $statut): static
    {
        return $this->state(fn () => ['status' => $statut]);
    }

    public function onSite(): static
    {
        return $this->state(fn () => [
            'type' => AppointmentType::OnSite,
            'location' => fake()->address(),
        ]);
    }
}
