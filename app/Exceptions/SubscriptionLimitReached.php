<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dépassement d'un plafond de plan.
 *
 * Le code métier `subscription.limit_reached` est stable et fait partie du
 * contrat d'API : le front s'en sert pour proposer une montée de plan plutôt
 * que d'afficher un message d'erreur générique. Le libellé, lui, peut changer.
 */
class SubscriptionLimitReached extends Exception
{
    public function __construct(
        public readonly string $resource,
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct(sprintf(
            'Le plan de cette entreprise autorise %d %s ; elle en compte déjà %d.',
            $limit,
            self::libelle($resource, $limit),
            $current,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'subscription.limit_reached',
            'message' => $this->getMessage(),
            'meta' => [
                'resource' => $this->resource,
                'limit' => $this->limit,
                'current' => $this->current,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function libelle(string $resource, int $limit): string
    {
        $singulier = match ($resource) {
            'projects' => 'projet',
            'users' => 'utilisateur',
            'prospects' => 'prospect',
            default => $resource,
        };

        return $limit > 1 ? $singulier.'s' : $singulier;
    }
}
