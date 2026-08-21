<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Écarte les prospects archivés de toute requête.
 *
 * **Un global scope plutôt qu'un `whereNull` répété**, pour la même raison que
 * `CompanyScope` : l'archivage doit sortir des listes *et* des taux, et ces
 * derniers sont calculés à cinq endroits différents — le tableau de bord, les
 * rapports, le résumé de la liste, la santé du pipeline, l'audit de qualité.
 * Un filtre à écrire cinq fois est un filtre qu'on oublie une fois, et
 * l'oublier ferait diverger deux écrans sans que rien ne le signale.
 *
 * La sortie est explicite et se voit en revue de code : `withArchived()` pour
 * inclure, `onlyArchived()` pour ne voir qu'eux. Le modèle contourne aussi ce
 * scope à la résolution de route, sans quoi la fiche d'un prospect archivé
 * répondrait 404 — or l'archivage range, il ne cache pas.
 */
/**
 * @implements Scope<Model>
 */
class NonArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.archived_at');
    }
}
