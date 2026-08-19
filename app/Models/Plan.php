<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan d'abonnement. Les plafonds valent NULL pour « illimité ».
 *
 * Le modèle n'est pas cloisonné par entreprise : les plans sont un catalogue
 * global géré depuis le back-office.
 */
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $max_projects
 * @property int|null $max_users
 * @property int|null $max_prospects
 * @property int $position
 */
#[Fillable(['name', 'slug', 'max_projects', 'max_users', 'max_prospects', 'position'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_projects' => 'integer',
            'max_users' => 'integer',
            'max_prospects' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Plafond pour une ressource donnée, NULL valant illimité.
     */
    public function limitFor(string $resource): ?int
    {
        return match ($resource) {
            'projects' => $this->max_projects,
            'users' => $this->max_users,
            'prospects' => $this->max_prospects,
            default => throw new \InvalidArgumentException(
                "Ressource inconnue pour un plafond de plan : {$resource}"
            ),
        };
    }
}
