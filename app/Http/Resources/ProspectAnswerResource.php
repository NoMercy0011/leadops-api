<?php

namespace App\Http\Resources;

use App\Models\ProspectAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProspectAnswer
 */
class ProspectAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'question_id' => $this->question_id,

            // La valeur brute, typée selon la question : chaîne, nombre,
            // booléen ou tableau. Le front la rend d'après le type déclaré.
            'value' => $this->value,

            // Version lisible, résolue côté serveur. Sans elle, le front devrait
            // rapprocher chaque valeur de la liste d'options pour retrouver son
            // libellé — un travail qu'il referait à chaque affichage.
            'display' => $this->afficher(),
        ];
    }

    private function afficher(): string
    {
        $question = $this->question;

        if ($question === null) {
            return (string) $this->value_text;
        }

        $valeur = $this->value;

        if ($question->type->storedAs() === 'boolean') {
            return $valeur ? 'Oui' : 'Non';
        }

        if ($question->type->requiresOptions()) {
            $libelles = $question->options
                ->whereIn('value', (array) $valeur)
                ->pluck('label');

            // Repli sur la valeur brute : une option supprimée depuis la
            // réponse ne doit pas faire disparaître celle-ci de l'affichage.
            return $libelles->isEmpty()
                ? implode(', ', (array) $valeur)
                : $libelles->implode(', ');
        }

        return is_array($valeur) ? implode(', ', $valeur) : (string) $valeur;
    }
}
