<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Exceptions\AlreadyErased;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ProspectAnswer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Effacement des données personnelles d'un prospect — lot 9, règle R4.
 *
 * Une **anonymisation**, pas une suppression : la fiche garde son projet, son
 * étape, son affectation, ses dates et ses jalons, pour que les taux du projet
 * ne bougent pas. Tout ce qui identifie la personne disparaît — identité,
 * coordonnées, texte libre, dates saisies au questionnaire, notes et lieu des
 * rendez-vous, notes et motifs du journal.
 *
 * Le texte affiché à l'utilisateur est provisoire, à valider sur le plan
 * juridique en fin de projet (hypothèse H-1 de la note du lot 9).
 */
class ProspectErasureService
{
    /**
     * Types de question dont la réponse peut identifier une personne. Les
     * nombres, oui/non, cases et choix restent : ils n'identifient personne et
     * servent les statistiques (décision du 2026-11-13).
     */
    private const QUESTIONS_IDENTIFIANTES = [
        QuestionType::Text,
        QuestionType::LongText,
        QuestionType::Email,
        QuestionType::Phone,
        QuestionType::Date,
    ];

    /**
     * Clés du journal qui portent du texte saisi librement.
     */
    private const CLES_LIBRES_DU_JOURNAL = ['note', 'motif', 'location', 'notes'];

    public function __construct(private readonly ActivityLogger $journal) {}

    /**
     * @return Collection<int, Prospect> les autres fiches de la même personne
     *                                   dans l'entreprise, signalées et non
     *                                   effacées (hypothèse H-2)
     *
     * @throws AlreadyErased
     */
    public function erase(Prospect $prospect): Collection
    {
        if ($prospect->isErased()) {
            throw new AlreadyErased;
        }

        // Le rapprochement se fait avant l'effacement : après, il n'y a plus
        // rien à rapprocher.
        $autresFiches = $this->autresFiches($prospect);

        DB::transaction(function () use ($prospect): void {
            $prospect->forceFill([
                'first_name' => '',
                'last_name' => null,
                'company_name' => null,
                'phone' => null,
                'email' => null,
                'phone_normalized' => null,
                'email_normalized' => null,
                'address' => null,
                'notes' => null,
                'next_action_note' => null,
                'erased_at' => now(),
            ])->save();

            ProspectAnswer::query()
                ->where('prospect_id', $prospect->id)
                ->whereHas('question', fn ($q) => $q->whereIn(
                    'type',
                    array_map(fn (QuestionType $t) => $t->value, self::QUESTIONS_IDENTIFIANTES),
                ))
                ->delete();

            Appointment::query()
                ->where('prospect_id', $prospect->id)
                ->update(['location' => null, 'notes' => null]);

            ProspectActivity::query()
                ->where('prospect_id', $prospect->id)
                ->get()
                ->each(function (ProspectActivity $activite): void {
                    $payload = $activite->payload;

                    if (! is_array($payload) || array_intersect_key($payload, array_flip(self::CLES_LIBRES_DU_JOURNAL)) === []) {
                        return;
                    }

                    $activite->forceFill([
                        'payload' => array_diff_key($payload, array_flip(self::CLES_LIBRES_DU_JOURNAL)),
                    ])->save();
                });

            $this->journal->erased($prospect);
        });

        ReportingService::forgetCache($prospect->company_id);

        return $autresFiches;
    }

    /**
     * @return Collection<int, Prospect>
     */
    private function autresFiches(Prospect $prospect): Collection
    {
        if ($prospect->email_normalized === null && $prospect->phone_normalized === null) {
            return new Collection;
        }

        return Prospect::query()
            ->withArchived()
            ->with('project:id,name')
            ->whereKeyNot($prospect->id)
            ->whereNull('erased_at')
            ->where(function ($q) use ($prospect): void {
                if ($prospect->email_normalized !== null) {
                    $q->orWhere('email_normalized', $prospect->email_normalized);
                }
                if ($prospect->phone_normalized !== null) {
                    $q->orWhere('phone_normalized', $prospect->phone_normalized);
                }
            })
            ->get();
    }
}
