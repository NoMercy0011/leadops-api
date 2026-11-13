<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Le modèle Company ne peut pas être cloisonné par CompanyScope — il *est* la
 * frontière. C'est donc cette policy qui tient lieu de garde-fou.
 */
class CompanyPolicy
{
    /**
     * Lister toutes les entreprises est une opération transversale.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Un utilisateur d'entreprise ne consulte que la sienne. La comparaison
     * passe par `company_id` et non par la relation : un Super Admin a un
     * company_id nul, et comparer deux NULL le ferait passer pour membre de
     * n'importe quelle entreprise.
     */
    public function view(User $user, Company $company): bool
    {
        return $user->isSuperAdmin()
            || ($user->company_id !== null && $user->company_id === $company->id);
    }

    /**
     * Consulter l'abonnement et la consommation de sa propre entreprise.
     *
     * Plus restrictif que `view` : le plan, l'échéance et les plafonds sont
     * une affaire de gestion, pas d'exploitation. Ni le commercial ni le
     * manager n'ont de décision à prendre à partir de ces chiffres — c'est
     * l'Admin Client qui change de plan, et lui seul que l'approche du plafond
     * concerne.
     *
     * Le Super Admin en est écarté sans que ce soit une restriction : il a sa
     * propre route sous `/admin`, qui prend l'entreprise en paramètre au lieu
     * de la déduire de l'utilisateur.
     */
    public function viewSubscription(User $user, Company $company): bool
    {
        return $user->isAdminClient() && $this->view($user, $company);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Suspendre ou réactiver relève du back-office seul : c'est un levier
     * commercial, pas un réglage que le client s'applique à lui-même.
     */
    public function changeStatus(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageSubscription(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }
}
