<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /options` — options des filtres projet et commercial (lot 10, R2).
 *
 * Les filtres lisaient la première page des listes paginées, bornée à 25 :
 * au-delà, un projet ou un commercial devenait introuvable. Cette route
 * renvoie tout le périmètre de l'utilisateur, sans pagination, en ne gardant
 * que ce qu'un filtre affiche. Le cloisonnement par entreprise et le périmètre
 * de rôle s'appliquent comme sur les listes.
 */
class FilterOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_if($viewer === null || $viewer->isSuperAdmin(), 403);

        return response()->json([
            'data' => [
                'projects' => Project::query()
                    ->visibleTo($viewer)
                    ->orderBy('name')
                    ->get(['id', 'name', 'status'])
                    ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status->value])
                    ->values(),
                'users' => User::query()
                    ->visibleTo($viewer)
                    ->orderBy('name')
                    ->get(['id', 'name', 'role'])
                    // `role_label` sert à l'équipe d'un projet (lot 11, constat C1).
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'role_label' => $u->role->label()])
                    ->values(),
            ],
        ]);
    }
}
