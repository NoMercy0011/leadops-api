<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\PipelineStage;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Écriture du journal des prospects — point de passage unique.
 *
 * Chaque événement métier passe par une méthode nommée plutôt que par un
 * `ProspectActivity::create()` dispersé dans les contrôleurs : la forme du
 * `payload` dépend du type, et la laisser au libre choix de chaque appelant
 * produirait un historique impossible à relire deux mois plus tard.
 */
final class ActivityLogger
{
    public function created(Prospect $prospect, bool $imported = false): void
    {
        $this->write(
            $prospect,
            $imported ? ActivityType::Imported : ActivityType::Created,
            ['stage' => $prospect->stage->name],
        );
    }

    public function stageChanged(
        Prospect $prospect,
        PipelineStage $ancienne,
        PipelineStage $nouvelle,
    ): void {
        // Les deux étapes sont conservées en clair, pas seulement leurs
        // identifiants : une étape renommée ou supprimée rendrait l'historique
        // illisible si l'on devait la résoudre a posteriori.
        $this->write($prospect, ActivityType::StageChanged, [
            'from_id' => $ancienne->id,
            'from' => $ancienne->name,
            'to_id' => $nouvelle->id,
            'to' => $nouvelle->name,
            'is_won' => $nouvelle->is_won,
            'is_lost' => $nouvelle->is_lost,
        ]);
    }

    public function assigned(Prospect $prospect, ?User $ancien, ?User $nouveau): void
    {
        $this->write(
            $prospect,
            $nouveau === null ? ActivityType::Unassigned : ActivityType::Assigned,
            [
                'from_id' => $ancien?->id,
                'from' => $ancien?->name,
                'to_id' => $nouveau?->id,
                'to' => $nouveau?->name,
            ],
        );
    }

    public function noteAdded(Prospect $prospect, string $note): void
    {
        $this->write($prospect, ActivityType::NoteAdded, ['note' => $note]);
    }

    public function nextActionPlanned(Prospect $prospect, ?string $quand, ?string $note): void
    {
        $this->write($prospect, ActivityType::NextActionPlanned, [
            'at' => $quand,
            'note' => $note,
        ]);
    }

    /**
     * @param  array<int, string>  $champs
     */
    public function fieldsUpdated(Prospect $prospect, array $champs): void
    {
        if ($champs === []) {
            return;
        }

        // Seuls les noms de champs sont journalisés, pas leurs valeurs : le
        // journal est consultable par toute l'équipe, et y recopier des
        // coordonnées personnelles en multiplierait inutilement les copies.
        $this->write($prospect, ActivityType::FieldsUpdated, ['fields' => $champs]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(Prospect $prospect, ActivityType $type, array $payload = []): void
    {
        ProspectActivity::query()->create([
            'company_id' => $prospect->company_id,
            'prospect_id' => $prospect->id,
            'user_id' => Auth::id(),
            'type' => $type,
            'payload' => array_filter($payload, fn ($valeur) => $valeur !== null),
            'occurred_at' => now(),
        ]);
    }
}
