<?php

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Prospect;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un projet ou un prospect archivé est en lecture seule — lot 9, règle R3.
 *
 * La règle vit ici, et nulle part ailleurs : `guard()` est appelée par les
 * policies d'écriture, et par le seul contrôleur qui doit laisser passer une
 * écriture précise (le changement de statut d'un projet, qui permet de le
 * réactiver).
 *
 * Une 422 et non une 403 : l'utilisateur a le droit d'agir, c'est l'état de
 * la ressource qui l'en empêche, et il se lève — on réactive, on désarchive.
 * L'écran le distingue d'un refus de droit par le code `archive.read_only`.
 */
class ArchivedReadOnly extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * @throws self
     */
    public static function guard(Project|Prospect $ressource): void
    {
        if ($ressource instanceof Prospect) {
            if ($ressource->isArchived()) {
                throw new self('Ce prospect est archivé : désarchivez-le pour le modifier.');
            }

            $ressource = $ressource->project;
        }

        if ($ressource->status->isArchived()) {
            throw new self("Le projet « {$ressource->name} » est archivé : réactivez-le pour le modifier.");
        }
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'archive.read_only',
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
