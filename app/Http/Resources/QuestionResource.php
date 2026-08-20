<?php

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Définition d'une question, telle que le front doit la rendre.
 *
 * C'est ce contrat qui permet au formulaire d'être générique : le front lit le
 * type, les options et les contraintes, et construit le champ. Il n'existe
 * aucun composant par projet ni par question — invariant n°3.
 *
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            'help' => $this->help,
            'required' => $this->required,
            'position' => $this->position,

            // Le front s'en sert pour choisir entre une valeur unique et un
            // tableau, sans avoir à recopier la table des types.
            'multi_value' => $this->type->isMultiValue(),
            'stored_as' => $this->type->storedAs(),

            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),

            // Conditionne l'interdit de changement de type : le front grise le
            // sélecteur plutôt que de laisser l'utilisateur buter sur un refus.
            'has_answers' => $this->when(
                $request->routeIs('projects.questions.*'),
                fn () => $this->hasAnswers(),
            ),
        ];
    }
}
