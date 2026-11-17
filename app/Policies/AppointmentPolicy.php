<?php

namespace App\Policies;

use App\Exceptions\ArchivedReadOnly;
use App\Exceptions\ErasedReadOnly;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\User;
use App\Support\TeamResolver;

/**
 * Périmètre de rôle sur les rendez-vous, après le cloisonnement tenant.
 *
 * Un commercial gère les siens de bout en bout — planifier, déplacer, clore —
 * parce que ce sont ses rendez-vous et que passer par son manager pour chaque
 * décalage d'horaire serait inutilisable au quotidien.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->estDansSonPerimetre($user, $appointment);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->peutEcrire($this->estDansSonPerimetre($user, $appointment), $appointment);
    }

    /**
     * Attribuer un rendez-vous à quelqu'un d'autre reste une décision
     * d'encadrement, comme l'affectation d'un prospect : sinon un commercial
     * pourrait se délester de ses rendez-vous sur un collègue.
     */
    public function assign(User $user, Appointment $appointment): bool
    {
        return $this->peutEcrire(
            ($user->isAdminClient() || $user->isManager()) && $this->estDansSonPerimetre($user, $appointment),
            $appointment,
        );
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->peutEcrire($this->estDansSonPerimetre($user, $appointment), $appointment);
    }

    /**
     * Le rendez-vous d'une fiche effacée, d'un prospect archivé ou d'un projet
     * archivé ne s'écrit plus (revue du lot 9, règles R3 et R4) : un PATCH y
     * remettait un lieu et des notes, donc des données personnelles. Le droit
     * de rôle se lit d'abord, la lecture seule ensuite.
     *
     * @throws ErasedReadOnly
     * @throws ArchivedReadOnly
     */
    private function peutEcrire(bool $droitDeRole, Appointment $appointment): bool
    {
        if ($droitDeRole) {
            ErasedReadOnly::guard($appointment->prospect);
            ArchivedReadOnly::guard($appointment->prospect);
        }

        return $droitDeRole;
    }

    private function estDansSonPerimetre(User $user, Appointment $appointment): bool
    {
        if ($user->isSuperAdmin()) {
            // Son périmètre est la plateforme, pas l'agenda commercial de ses
            // clients.
            return false;
        }

        if ($user->isAdminClient()) {
            return true;
        }

        // Un rendez-vous sans commercial est à prendre : le masquer le rendrait
        // introuvable jusqu'à ce que quelqu'un le réclame.
        // Il suit la visibilité de son prospect (revue du lot 9) : visible
        // pour qui voit le prospect, caché pour les autres. Sans cette règle,
        // il exposait son prospect à toute l'équipe, quel que soit le projet.
        if ($appointment->user_id === null) {
            return Prospect::query()->withArchived()->visibleTo($user)->whereKey($appointment->prospect_id)->exists();
        }

        return TeamResolver::scopeUserIds($user)->contains($appointment->user_id);
    }
}
