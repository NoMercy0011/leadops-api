<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Variant;

/**
 * La variante est un axe de reporting partagé par toute l'entreprise. La
 * lecture est ouverte à tous ses membres — un commercial doit pouvoir voir la
 * variante d'un projet — mais l'édition du vocabulaire reste à l'Admin Client :
 * laisser chacun créer des variantes reproduirait exactement le désordre que la
 * table de référence évite.
 */
class VariantPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Variant $variant): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdminClient();
    }

    public function update(User $user, Variant $variant): bool
    {
        return $user->isAdminClient();
    }

    public function delete(User $user, Variant $variant): bool
    {
        return $user->isAdminClient();
    }
}
