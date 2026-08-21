<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Deuxième couche d'autorisation, après le cloisonnement tenant.
 *
 * CompanyScope a déjà garanti que le projet appartient à l'entreprise de
 * l'utilisateur au moment où ces méthodes sont appelées : un projet d'une autre
 * entreprise est introuvable, la requête échoue en 404 avant d'arriver ici.
 * Ces règles ne traitent donc que du périmètre de rôle.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    /**
     * Manager et commercial ne voient que les projets où ils sont affectés.
     * Le filtrage de liste est fait par `Project::scopeVisibleTo` ; cette
     * méthode couvre l'accès direct par identifiant.
     */
    public function view(User $user, Project $project): bool
    {
        if ($user->isAdminClient()) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return $project->members()->whereKey($user->id)->exists();
    }

    /**
     * La création d'un projet consomme un quota d'abonnement et engage la
     * stratégie commerciale : elle reste à l'Admin Client.
     */
    public function create(User $user): bool
    {
        return $user->isAdminClient();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isAdminClient();
    }

    /**
     * Modifier le pipeline change la signification des données déjà saisies
     * pour tous les commerciaux du projet : c'est une décision d'entreprise.
     */
    public function managePipeline(User $user, Project $project): bool
    {
        return $user->isAdminClient();
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $user->isAdminClient();
    }

    /**
     * Personne ne supprime un projet, pas même l'Admin Client.
     *
     * Ce n'est pas une restriction de rôle mais une décision de conception :
     * les clés étrangères sont en cascade, et un projet effacé emporterait ses
     * prospects, leur journal et leurs rendez-vous. Le geste correspondant est
     * l'archivage, qui passe par `update`.
     *
     * La méthode est conservée plutôt que supprimée pour que la réponse à
     * `authorize('delete', $project)` soit un refus explicite, et non le refus
     * par défaut qu'on obtient quand une policy n'a rien à dire — les deux se
     * ressemblent à l'exécution, seul le premier se lit.
     */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }
}
