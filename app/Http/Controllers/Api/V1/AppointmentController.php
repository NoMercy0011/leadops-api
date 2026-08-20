<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Services\AppointmentService;
use App\Support\TimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $rendezVous) {}

    /**
     * Agenda, borné à une plage de journées **locales**.
     *
     * Les bornes sont calculées dans le fuseau de l'entreprise puis converties
     * en UTC : un `whereDate` comparerait une colonne UTC à une date du serveur
     * et amputerait silencieusement la journée aux deux extrémités.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $donnees = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'user_id' => ['sometimes', 'string'],
            'project_id' => ['sometimes', 'integer'],
            'prospect_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(AppointmentStatus::class)],
        ]);

        // La fiche d'un prospect montre tout son historique de rendez-vous :
        // la borner à une période obligerait l'appelant à deviner une plage
        // assez large, ou à ramener tout l'agenda pour le filtrer ensuite.
        $prospectId = $donnees['prospect_id'] ?? null;

        $fuseau = TimezoneResolver::for($request->user());

        $debut = isset($donnees['from'])
            ? CarbonImmutable::parse($donnees['from'])
            : TimezoneResolver::now($fuseau);

        $fin = isset($donnees['to'])
            ? CarbonImmutable::parse($donnees['to'])
            // Sans borne de fin, on affiche le mois courant : c'est la vue par
            // défaut du calendrier, et renvoyer tout l'historique ferait
            // grossir la réponse sans usage.
            : $debut->endOfMonth();

        [$ouverture, $fermeture] = TimezoneResolver::rangeBounds($fuseau, $debut, $fin);

        $appointments = Appointment::query()
            ->visibleTo($request->user())
            // Le filtre par prospect remplace les bornes temporelles plutôt
            // que de s'y ajouter : on veut alors tout son historique.
            ->when(
                $prospectId !== null,
                fn ($q) => $q->where('prospect_id', $prospectId),
                fn ($q) => $q->between($ouverture, $fermeture),
            )
            ->with(['prospect', 'project', 'user'])
            ->when(
                isset($donnees['user_id']),
                fn ($q) => $donnees['user_id'] === 'none'
                    ? $q->whereNull('user_id')
                    : $q->where('user_id', (int) $donnees['user_id'])
            )
            ->when(
                isset($donnees['project_id']),
                fn ($q) => $q->where('project_id', $donnees['project_id'])
            )
            ->when(
                isset($donnees['status']),
                fn ($q) => $q->where('status', $donnees['status'])
            )
            ->orderBy('scheduled_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $donnees = $this->valider($request);

        // Le prospect détermine le projet : le laisser saisir séparément
        // ouvrirait la porte à un rendez-vous rattaché au mauvais projet, que
        // rien ne rattraperait ensuite.
        $prospect = Prospect::query()->findOrFail($donnees['prospect_id']);

        $appointment = $this->rendezVous->create($prospect, $donnees);

        return (new AppointmentResource($appointment->load(['prospect', 'project', 'user'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource(
            $appointment->load(['prospect', 'project', 'user'])
        );
    }

    public function update(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->authorize('update', $appointment);

        $donnees = $this->valider($request, partiel: true);

        // Changer de commercial relève de l'encadrement, comme l'affectation
        // d'un prospect : sinon un commercial se déchargerait de ses
        // rendez-vous sur un collègue.
        if (array_key_exists('user_id', $donnees)) {
            $this->authorize('assign', $appointment);
        }

        return new AppointmentResource(
            $this->rendezVous->update($appointment, $donnees)
                ->load(['prospect', 'project', 'user'])
        );
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function valider(Request $request, bool $partiel = false): array
    {
        $presence = $partiel ? 'sometimes' : 'required';
        $companyId = $request->user()->company_id;

        return $request->validate([
            'prospect_id' => [
                $partiel ? 'prohibited' : 'required', 'integer',
                Rule::exists('prospects', 'id')->where('company_id', $companyId),
            ],
            'scheduled_at' => [$presence, 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'type' => [$presence, Rule::enum(AppointmentType::class)],
            'status' => ['sometimes', Rule::enum(AppointmentStatus::class)],
            'user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            // Obligatoire pour un rendez-vous physique seulement : un lieu
            // exigé pour un appel téléphonique serait un champ vide de sens
            // que l'utilisateur remplirait n'importe comment.
            'location' => [
                'nullable', 'string', 'max:255',
                Rule::requiredIf(
                    fn () => ($request->input('type') === AppointmentType::OnSite->value)
                ),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], [
            'prospect_id.prohibited' => 'Le prospect d\'un rendez-vous ne peut pas changer : créez-en un nouveau.',
            'location.required' => 'Un rendez-vous physique demande une adresse.',
        ]);
    }
}
