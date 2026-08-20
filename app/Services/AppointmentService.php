<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Création et évolution d'un rendez-vous.
 *
 * Le service existe pour une raison précise : un rendez-vous est un événement
 * de la vie du prospect, et doit donc apparaître dans son journal. Laisser le
 * contrôleur écrire directement en base ferait diverger l'agenda de
 * l'historique — deux vues du même fait, qui ne se rejoindraient plus.
 */
final class AppointmentService
{
    /**
     * @param  array<string, mixed>  $donnees
     */
    public function create(Prospect $prospect, array $donnees): Appointment
    {
        return DB::transaction(function () use ($prospect, $donnees) {
            $appointment = new Appointment($donnees);

            $appointment->company_id = $prospect->company_id;
            // Le projet vient du prospect, jamais de la requête : les deux
            // pourraient diverger, et rien ne le rattraperait ensuite.
            $appointment->project_id = $prospect->project_id;
            $appointment->prospect_id = $prospect->id;

            // À défaut de commercial désigné, le rendez-vous revient à celui
            // qui suit déjà le prospect. C'est le cas courant, et l'imposer en
            // saisie serait une redondance de plus à chaque planification.
            if (! array_key_exists('user_id', $donnees)) {
                $appointment->user_id = $prospect->assigned_user_id;
            }

            $appointment->save();

            $this->journaliser($appointment, ActivityType::AppointmentScheduled);

            return $appointment;
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function update(Appointment $appointment, array $donnees): Appointment
    {
        $ancienStatut = $appointment->status;
        $ancienneDate = $appointment->scheduled_at;

        return DB::transaction(function () use ($appointment, $donnees, $ancienStatut, $ancienneDate) {
            $appointment->fill($donnees);
            $appointment->save();

            // Deux changements distincts méritent chacun leur trace : déplacer
            // un rendez-vous et le clore ne racontent pas la même chose.
            if (! $appointment->scheduled_at->equalTo($ancienneDate)) {
                $this->journaliser($appointment, ActivityType::AppointmentRescheduled, [
                    'from' => $ancienneDate->toIso8601String(),
                ]);
            }

            if ($appointment->status !== $ancienStatut) {
                $this->journaliser($appointment, ActivityType::AppointmentStatusChanged, [
                    'from' => $ancienStatut->value,
                    'from_label' => $ancienStatut->label(),
                ]);
            }

            return $appointment->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function journaliser(Appointment $appointment, ActivityType $type, array $extra = []): void
    {
        ProspectActivity::query()->create([
            'company_id' => $appointment->company_id,
            'prospect_id' => $appointment->prospect_id,
            'user_id' => Auth::id(),
            'type' => $type,
            'payload' => [
                'appointment_id' => $appointment->id,
                // La date est conservée en clair dans le journal : résoudre le
                // rendez-vous a posteriori le rendrait illisible dès qu'il est
                // supprimé.
                'at' => $appointment->scheduled_at->toIso8601String(),
                'type' => $appointment->type->label(),
                'status' => $appointment->status->value,
                'status_label' => $appointment->status->label(),
                ...$extra,
            ],
            'occurred_at' => now(),
        ]);
    }
}
