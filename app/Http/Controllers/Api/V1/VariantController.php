<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VariantResource;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VariantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Variant::class);

        return VariantResource::collection(
            Variant::query()->withCount('projects')->orderBy('position')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Variant::class);

        $donnees = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('variants', 'name')->where('company_id', $request->user()->company_id),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $variant = Variant::query()->create($donnees);

        return (new VariantResource($variant))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(Request $request, Variant $variant): VariantResource
    {
        $this->authorize('update', $variant);

        $donnees = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('variants', 'name')
                    ->where('company_id', $request->user()->company_id)
                    ->ignore($variant),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $variant->update($donnees);

        return new VariantResource($variant);
    }

    public function destroy(Variant $variant): JsonResponse
    {
        $this->authorize('delete', $variant);

        // Supprimer une variante encore utilisée détacherait silencieusement
        // les projets concernés — la clé étrangère est en `nullOnDelete` — et
        // ferait disparaître un axe de reporting sans que personne ne le voie.
        $utilisee = $variant->projects()->count();

        if ($utilisee > 0) {
            throw ValidationException::withMessages([
                'variant' => "Cette variante est utilisée par {$utilisee} projet".($utilisee > 1 ? 's' : '').'. Réaffectez-les avant de la supprimer.',
            ]);
        }

        $variant->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
