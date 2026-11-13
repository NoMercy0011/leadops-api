<?php

namespace App\Policies;

use App\Exceptions\ArchivedReadOnly;
use App\Exceptions\ErasedReadOnly;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;
use App\Support\TeamResolver;

/**
 * Périmètre de rôle sur les prospects, après le cloisonnement tenant.
 *
 * La lecture de `manager_id` passe exclusivement par TeamResolver — couture
 * §3.8 : le jour où la hiérarchie changera de forme, cette classe n'aura pas à
 * être relue.
 */
class ProspectPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Prospect $prospect): bool
    {
        return $this->estDansSonPerimetre($user, $prospect);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function update(User $user, Prospect $prospect): bool
    {
        return $this->peutEcrire($this->estDansSonPerimetre($user, $prospect), $prospect);
    }

    /**
     * Affecter un prospect à quelqu'un d'autre est une décision d'encadrement.
     * Un commercial qui pourrait se réaffecter les prospects des autres
     * contournerait la répartition décidée par son manager.
     */
    public function assign(User $user, Prospect $prospect): bool
    {
        return $this->peutEcrire($this->encadre($user, $prospect), $prospect);
    }

    /**
     * Archiver et désarchiver : même droit que l'affectation, mais sans la
     * garde d'archivage — c'est précisément le geste qui la lève. Une fiche
     * effacée, elle, n'accepte plus rien.
     *
     * @throws ErasedReadOnly
     */
    public function archive(User $user, Prospect $prospect): bool
    {
        $droit = $this->encadre($user, $prospect);

        if ($droit) {
            ErasedReadOnly::guard($prospect);
        }

        return $droit;
    }

    /**
     * Effacer les données personnelles (lot 9, règle R4) : un geste
     * irréversible qui engage l'entreprise, réservé à l'Admin Client. Il
     * reste permis sur un prospect archivé, et sur un projet archivé.
     */
    public function erase(User $user, Prospect $prospect): bool
    {
        return $user->isAdminClient();
    }

    private function encadre(User $user, Prospect $prospect): bool
    {
        return ($user->isAdminClient() || $user->isManager())
            && $this->estDansSonPerimetre($user, $prospect);
    }

    /**
     * Le droit de rôle se lit d'abord, la lecture seule ensuite (lot 9) : une
     * fiche effacée (règle R4), un prospect archivé ou dont le projet l'est
     * (règle R3) refusent l'écriture.
     *
     * @throws ErasedReadOnly
     * @throws ArchivedReadOnly
     */
    private function peutEcrire(bool $droitDeRole, Prospect $prospect): bool
    {
        if ($droitDeRole) {
            ErasedReadOnly::guard($prospect);
            ArchivedReadOnly::guard($prospect);
        }

        return $droitDeRole;
    }

    public function bulkAssign(User $user): bool
    {
        return $user->isAdminClient() || $user->isManager();
    }

    /**
     * L'import engage le quota d'abonnement de toute l'entreprise.
     */
    public function import(User $user): bool
    {
        return $user->isAdminClient() || $user->isManager();
    }

    public function export(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    /**
     * Le Super Admin est explicitement exclu : son périmètre est la gestion de
     * la plateforme, pas la consultation des données commerciales de ses
     * clients. Rien ne l'y autorise, même techniquement hors cloisonnement.
     */
    private function estDansSonPerimetre(User $user, Prospect $prospect): bool
    {
        if ($user->isSuperAdmin()) {
            return false;
        }

        if ($user->isAdminClient()) {
            return true;
        }

        // Un prospect non affecté reste dans le périmètre : c'est celui qu'un
        // manager doit pouvoir distribuer et qu'un commercial peut réclamer.
        // Pour un manager, seulement dans un projet de son équipe (lot 9,
        // règle R6) — la même borne que `Prospect::visibleTo`.
        if ($prospect->assigned_user_id === null) {
            return ! $user->isManager()
                || Project::query()->visibleTo($user)->whereKey($prospect->project_id)->exists();
        }

        return TeamResolver::scopeUserIds($user)->contains($prospect->assigned_user_id);
    }
}
