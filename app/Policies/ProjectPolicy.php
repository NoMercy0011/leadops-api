<?php

namespace App\Policies;

use App\Exceptions\ArchivedReadOnly;
use App\Models\Project;
use App\Models\User;
use App\Support\TeamResolver;

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

        // Même règle que `Project::scopeVisibleTo`, et c'est tout l'objet de
        // cette ligne : la liste appliquait le périmètre d'équipe, la fiche
        // exigeait une adhésion nominative. Un manager voyait donc trois
        // projets dans `/projets` dont chaque fiche répondait 403 — une liste
        // et son détail qui se contredisent.
        //
        // Pour un commercial, `scopeUserIds` ne contient que lui : le
        // comportement est inchangé. Pour un manager, ce sont ses commerciaux,
        // ce qui est la définition même de son périmètre — invariant n°5.
        return $project->members()
            ->whereIn('users.id', TeamResolver::scopeUserIds($user))
            ->exists();
    }

    /**
     * Lire l'activité agrégée du projet — santé du pipeline, durées, immobiles.
     *
     * Même règle que les rapports : agréger l'activité d'une équipe relève de
     * l'encadrement. Un commercial dispose de sa liste de prospects, où figure
     * déjà tout ce qui le concerne, et n'a pas à connaître le rythme de ses
     * collègues.
     *
     * Un manager y a droit, mais sur son seul périmètre : le service applique
     * `visibleTo`, si bien que ses chiffres portent sur son équipe.
     */
    public function viewReports(User $user, Project $project): bool
    {
        return ($user->isAdminClient() || $user->isManager())
            && $this->view($user, $project);
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
        return $this->peutEcrire($user->isAdminClient(), $project);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->peutEcrire($user->isAdminClient(), $project);
    }

    /**
     * Le droit de rôle se lit d'abord : un rôle sans droit reçoit 403, pas
     * l'explication de l'archivage. Ensuite seulement, un projet archivé
     * refuse l'écriture (lot 9, règle R3).
     *
     * `update` n'y passe pas : le changement de statut doit rester possible
     * sur un projet archivé, pour le réactiver. Le contrôleur applique la
     * même garde aux autres champs.
     *
     * @throws ArchivedReadOnly
     */
    private function peutEcrire(bool $droitDeRole, Project $project): bool
    {
        if ($droitDeRole) {
            ArchivedReadOnly::guard($project);
        }

        return $droitDeRole;
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
