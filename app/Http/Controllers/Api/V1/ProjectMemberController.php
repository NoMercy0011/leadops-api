<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Services\ProjectJournal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectMemberController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        return UserResource::collection($project->members()->orderBy('name')->get());
    }

    /**
     * Remplace l'équipe affectée au projet.
     *
     * L'opération est un remplacement complet et non un ajout : l'interface
     * présente une liste à cocher, et un `sync` traduit exactement ce que
     * l'utilisateur voit à l'écran. Un `attach` obligerait le front à calculer
     * lui-même les retraits, avec le risque d'oubli que cela comporte.
     */
    public function __construct(
        private readonly ProjectJournal $journal,
    ) {}

    public function sync(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $project);

        $donnees = $request->validate([
            'member_ids' => ['present', 'array'],
            'member_ids.*' => [
                'integer',
                // Rattachement à la même entreprise vérifié explicitement : la
                // clé étrangère accepterait n'importe quel utilisateur existant.
                Rule::exists('users', 'id')->where('company_id', $request->user()->company_id),
            ],
        ]);

        // Les noms sont relevés avant et après : le journal doit rester
        // lisible même après le départ d'un compte, et un identifiant seul ne
        // dirait plus rien.
        $avant = $project->members()->pluck('name')->all();

        // La modification et sa trace au journal réussissent ou échouent
        // ensemble (lot 9, règle R7).
        DB::transaction(function () use ($project, $donnees, $avant): void {
            $project->members()->sync($donnees['member_ids']);

            $this->journal->membersChanged(
                $project,
                $avant,
                $project->members()->pluck('name')->all(),
            );
        });

        return UserResource::collection($project->members()->orderBy('name')->get());
    }
}
