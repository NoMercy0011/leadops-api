<?php

namespace Database\Factories;

use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;
use App\Support\ContactNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Numéro malgache plausible : le normaliseur valide réellement le plan
        // de numérotation, un faux numéro produirait `phone_normalized` nul et
        // rendrait les tests de doublons inopérants.
        $telephone = '+2613'.fake()->numberBetween(2, 4).fake()->numerify('#######');
        $email = fake()->unique()->safeEmail();

        return [
            'project_id' => Project::factory(),
            'company_id' => fn (array $attributs) => Project::query()
                ->acrossAllCompanies()
                ->whereKey($attributs['project_id'])
                ->value('company_id'),
            'stage_id' => fn (array $attributs) => PipelineStage::query()
                ->acrossAllCompanies()
                ->where('project_id', $attributs['project_id'])
                ->orderBy('position')
                ->value('id'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->optional()->company(),
            'phone' => $telephone,
            'email' => $email,
            'phone_normalized' => ContactNormalizer::phone($telephone),
            'email_normalized' => ContactNormalizer::email($email),
            'source' => fake()->randomElement(['Salon', 'Site web', 'Recommandation', 'Appel entrant']),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'company_id' => $project->company_id,
            'stage_id' => $project->stages()->orderBy('position')->value('id'),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_user_id' => $user->id]);
    }

    public function unassigned(): static
    {
        return $this->state(fn () => ['assigned_user_id' => null]);
    }

    public function atStage(PipelineStage $stage): static
    {
        return $this->state(fn () => [
            'stage_id' => $stage->id,
            'project_id' => $stage->project_id,
            'company_id' => $stage->company_id,
        ]);
    }
}
