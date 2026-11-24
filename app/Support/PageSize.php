<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Taille de page demandée par le client, bornée — lot 10, règle R1.
 *
 * `per_page` n'avait pas de plafond : `?per_page=1000000` chargeait toute la
 * base visible sur un plan Enterprise illimité. Une valeur absente, nulle,
 * négative ou illisible retombe sur la valeur par défaut de la liste ; au-delà
 * du plafond, le plafond s'applique.
 */
final class PageSize
{
    public const MAX = 100;

    public static function from(Request $request, int $parDefaut = 25): int
    {
        $demande = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if ($demande === false || $demande < 1) {
            return $parDefaut;
        }

        return min($demande, self::MAX);
    }
}
