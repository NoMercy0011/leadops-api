<?php

namespace App\Support;

use App\Models\Prospect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Requête de base d'une liste de prospects : cloisonnement, périmètre de rôle
 * et filtres de l'URL.
 *
 * Partagée par la liste, son résumé, ses facettes **et l'export** (lot 9,
 * faille F17), pour que tous portent exactement sur le même ensemble. L'export
 * filtrait de son côté et ne lisait que `project_id` : dans la vue
 * « Archivés », il renvoyait les actifs. Deux constructions séparées finissent
 * par diverger, et rien ne le signale.
 */
final class ProspectListFilter
{
    /**
     * @param  list<string>  $sauf  filtres à ne pas appliquer — les facettes
     *                              d'une famille se comptent en ignorant son
     *                              propre filtre.
     * @return Builder<Prospect>
     */
    public static function query(Request $request, array $sauf = []): Builder
    {
        return Prospect::query()
            // Trois couches : CompanyScope borne à l'entreprise, NonArchivedScope
            // écarte les dossiers rangés, `visibleTo` applique la restriction de
            // rôle. Le second se retire explicitement pour la vue « archivés »,
            // qui est le seul moyen de les retrouver.
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->onlyArchived(),
            )
            ->visibleTo($request->user())
            ->when(
                in_array('project_id', $sauf, true) ? 0 : $request->integer('project_id'),
                fn ($q, int $id) => $q->where('project_id', $id)
            )
            ->when(
                // Zéro plutôt qu'un booléen : `when` transmet la valeur au
                // rappel, et une condition booléenne y ferait arriver `true`
                // en guise d'identifiant.
                in_array('stage_id', $sauf, true) ? 0 : $request->integer('stage_id'),
                fn ($q, int $id) => $q->where('stage_id', $id)
            )
            ->when(
                ! in_array('assigned_user_id', $sauf, true)
                    && $request->has('assigned_user_id'),
                function ($q) use ($request) {
                    $valeur = $request->input('assigned_user_id');

                    // « none » filtre les non affectés, ce qu'un identifiant
                    // numérique ne peut pas exprimer.
                    return $valeur === 'none'
                        ? $q->whereNull('assigned_user_id')
                        : $q->where('assigned_user_id', (int) $valeur);
                }
            )
            ->when(
                $request->string('search')->trim()->value(),
                fn ($q, string $terme) => $q->where(function ($sub) use ($terme) {
                    $sub->where('first_name', 'like', "%{$terme}%")
                        ->orWhere('last_name', 'like', "%{$terme}%")
                        ->orWhere('company_name', 'like', "%{$terme}%")
                        ->orWhere('phone', 'like', "%{$terme}%")
                        ->orWhere('email', 'like', "%{$terme}%");
                })
            );
    }
}
