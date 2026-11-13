<?php

namespace App\Exceptions;

use App\Models\Prospect;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une fiche dont les données personnelles ont été effacées n'accepte plus
 * aucune écriture — lot 9, règle R4.
 *
 * À la différence de l'archivage, rien ne lève cet état : l'effacement est
 * irréversible. La garde est appelée par les policies d'écriture, avant celle
 * de l'archivage.
 */
class ErasedReadOnly extends Exception
{
    /**
     * @throws self
     */
    public static function guard(Prospect $prospect): void
    {
        if ($prospect->isErased()) {
            throw new self('Les données personnelles de ce prospect ont été effacées : la fiche ne se modifie plus.');
        }
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'erasure.read_only',
            'message' => $this->getMessage(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
