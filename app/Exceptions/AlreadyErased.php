<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second effacement d'une même fiche — lot 9.
 *
 * Un 409 plutôt qu'un 200 silencieux : rejouer l'effacement écrirait un second
 * événement au journal, et l'écran doit pouvoir dire « déjà effacée » plutôt
 * que de laisser croire qu'il vient d'agir.
 */
class AlreadyErased extends Exception
{
    public function __construct()
    {
        parent::__construct('Les données personnelles de ce prospect ont déjà été effacées.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'erasure.already_erased',
            'message' => $this->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
