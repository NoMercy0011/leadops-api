<?php

namespace App\Policies;

use App\Models\Appointment;
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
        return $this->estDansSonPerimetre($user, $appointment);
    }

    /**
     * Attribuer un rendez-vous à quelqu'un d'autre reste une décision
     * d'encadrement, comme l'affectation d'un prospect : sinon un commercial
     * pourrait se délester de ses rendez-vous sur un collègue.
     */
    public function assign(User $user, Appointment $appointment): bool
    {
        return ($user->isAdminClient() || $user->isManager())
            && $this->estDansSonPerimetre($user, $appointment);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->estDansSonPerimetre($user, $appointment);
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
        if ($appointment->user_id === null) {
            return true;
        }

        return TeamResolver::scopeUserIds($user)->contains($appointment->user_id);
    }
}
