<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Une entreprise cliente, c'est-à-dire un tenant.
 *
 * Ce modèle ne porte pas le trait BelongsToCompany : il *est* la frontière,
 * il ne peut donc pas être cloisonné par elle. Son accès est restreint par
 * CompanyPolicy — seul le Super Admin voit les autres entreprises.
 */
/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CompanyStatus $status
 * @property string $timezone
 * @property string $default_country_code
 * @property Subscription|null $subscription
 */
#[Fillable(['name', 'slug', 'status', 'timezone', 'default_country_code'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * Valeur par défaut portée aussi par le modèle, et pas seulement par la
     * colonne. Un défaut défini en base n'est appliqué qu'à l'insertion :
     * l'instance en mémoire garde un `status` nul, et toute sérialisation
     * faite dans la foulée d'une création échoue.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => CompanyStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    /**
     * Une entreprise peut écrire si elle est active *et* si son abonnement
     * l'autorise. Les deux conditions sont distinctes : une entreprise
     * suspendue par le back-office reste bloquée même avec un abonnement payé.
     */
    public function allowsWrites(): bool
    {
        return $this->isActive()
            && $this->subscription !== null
            && $this->subscription->allowsWrites();
    }
}
