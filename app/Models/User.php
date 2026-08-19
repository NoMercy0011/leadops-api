<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int|null $company_id
 * @property UserRole $role
 * @property int|null $manager_id
 */
#[Fillable(['company_id', 'name', 'email', 'password', 'role', 'manager_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isAdminClient(): bool
    {
        return $this->role === UserRole::AdminClient;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function isCommercial(): bool
    {
        return $this->role === UserRole::Commercial;
    }

    /**
     * Deux utilisateurs partagent-ils la même entreprise ?
     *
     * Renvoie false pour un Super Admin, dont le company_id est nul : deux
     * comptes sans entreprise ne sont pas « dans la même entreprise », et une
     * comparaison naïve de deux NULL le laisserait croire.
     */
    public function sharesCompanyWith(self $other): bool
    {
        return $this->company_id !== null
            && $this->company_id === $other->company_id;
    }

    /**
     * Restreint une requête aux utilisateurs visibles par celui-ci, en surcouche
     * du cloisonnement tenant déjà appliqué par CompanyScope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, self $viewer): Builder
    {
        return match ($viewer->role) {
            UserRole::SuperAdmin, UserRole::AdminClient => $query,
            UserRole::Manager => $query->where(function (Builder $q) use ($viewer) {
                $q->where('manager_id', $viewer->id)->orWhere('id', $viewer->id);
            }),
            UserRole::Commercial => $query->whereKey($viewer->id),
        };
    }
}
