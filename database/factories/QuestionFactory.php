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
            // `position` est posée par `configure()`, qui seul sait tenir le
            // compte à l'intérieur d'un lot.
        ];
    }

    /**
     * Attribue une position séquentielle, comme le fait QuestionnaireService en
     * production.
     *
     * Un tirage aléatoire satisfait la contrainte d'unicité mais rend l'ordre
     * d'affichage imprévisible : des tests d'ordre passent ou échouent selon le
     * tirage, ce qui est pire qu'un échec franc.
     *
     * Le compteur de lot est indispensable : `count(4)->create()` construit les
     * quatre instances **avant** d'en persister aucune, si bien qu'une lecture
     * de `max(position)` renverrait la même valeur pour les quatre. Il est
     * capturé par référence plutôt que porté par `$this` — les factories se
     * clonent à chaque appel de méthode, et l'état porté par l'objet se
     * perdrait en route.
     */
    public function configure(): static
    {
        $dernieres = [];

        return $this->afterMaking(function (Question $question) use (&$dernieres): void {
            $projet = (int) $question->project_id;

            // `withTrashed` : une question supprimée garde sa position, et
            // l'ignorer heurterait la clé unique (project_id, position).
            $enBase = (int) Question::query()
                ->withTrashed()
                ->acrossAllCompanies()
                ->where('project_id', $projet)
                ->max('position');

            $suivante = max($enBase, $dernieres[$projet] ?? 0) + 1;

            $dernieres[$projet] = $suivante;
            $question->position = $suivante;
        });
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
