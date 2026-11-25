<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refus propres à l'entretien guidé — lot 13, contrat §0.
 *
 * L'écran distingue les cas par leur `code`, jamais par leur message.
 */
class InterviewRefused extends Exception
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $code_,
        private readonly int $statut,
        string $message,
        private readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyOpen(string $auteur, string $debut): self
    {
        return new self('interview.already_open', 409, "Un entretien est déjà en cours sur ce prospect ({$auteur}).", [
            'user' => $auteur,
            'started_at' => $debut,
        ]);
    }

    public static function emptyQuestionnaire(): self
    {
        return new self('interview.empty_questionnaire', 422, "Ce projet n'a pas encore de questionnaire.");
    }

    public static function closed(): self
    {
        return new self('interview.closed', 409, 'Cet entretien est terminé ou abandonné : il ne se modifie plus.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(array_filter([
            'code' => $this->code_,
            'message' => $this->getMessage(),
            'meta' => $this->meta === [] ? null : $this->meta,
        ], fn ($v) => $v !== null), $this->statut);
    }
}
