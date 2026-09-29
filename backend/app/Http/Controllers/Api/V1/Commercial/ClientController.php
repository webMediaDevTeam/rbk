<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Services\CallWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function __construct(private CallWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        // Filtres + exclusions + tri **partagés** avec la réservation d'un
        // lot (`Client::scopeProspectList`) : la page et le lot réservé
        // décrivent exactement les mêmes clients, dans le même ordre.
        $query = Client::query()->prospectList($request->all());

        $perPage = min((int) $request->input('per_page', 20), 300);
        $clients = $query->paginate($perPage);

        // Statut affiché : dernière réservation de chaque client en 1 requête
        // (sinon une requête par ligne à formatter).
        Client::loadLatestReservations($clients->getCollection());

        $formatted = $clients->getCollection()->map(fn ($client) => $this->formatClient($client));

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $formatted,
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                ],
            ],
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Client::query()
            ->whereHas('reservations', function ($q) use ($user) {
                $q->where('comercial_id', $user->id);
            });

        $perPage = min((int) $request->input('per_page', 20), 300);
        $clients = $query->paginate($perPage);

        // Statut affiché : dernière réservation de chaque client en 1 requête
        // (sinon une requête par ligne à formatter).
        Client::loadLatestReservations($clients->getCollection());

        // Numéro de téléphone : même règle que le détail — visible seulement
        // si le connecté détient la réservation en cours du client. La
        // relation `latestReservation` est déjà préchargée ci-dessus.
        $formatted = $clients->getCollection()->map(fn ($client) => $this->formatClient(
            $client,
            false,
            $this->canSeePhone($client, $this->latestReservationOf($client))
        ));

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $formatted,
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $client = Client::with(['reservations.comercial', 'reservations.rappel', 'notes.sender'])
            ->find($id);

        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable.',
            ], 404);
        }

        $canSeePhone = $this->canSeePhone($client, $this->latestReservationOf($client));

        return response()->json([
            'success' => true,
            'data' => [
                'client' => $this->formatClient($client, true, $canSeePhone),
            ],
        ]);
    }

    public function blacklist(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:5000',
        ]);

        $user = $request->user();
        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $user, $validated) {
            // `handleBlacklist()` fait tout : statut `BLACKLISTED`,
            // `is_blacklisted = true`, `returned_at` vidé, rappels annulés,
            // note `BLACKLISTED` (motif optionnel, émetteur = l'employé).
            $this->workflow->apply($client, null, Note::TYPE_BLACKLISTED, $validated, $user);

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }

    /**
     * Dernière réservation du client — celle qui fait foi pour « qui détient
     * le client en ce moment ». Utilise la relation préchargée quand elle
     * l'est (listes), sinon la collection `reservations` du détail. Même
     * départage que `Client::latestReservation()` (`created_at`, puis `id`).
     */
    private function latestReservationOf(Client $client): ?Reservation
    {
        if ($client->relationLoaded('latestReservation')) {
            return $client->latestReservation;
        }

        return $client->reservations
            ->sortByDesc(fn (Reservation $r) => [$r->created_at, $r->id])
            ->first();
    }

    /**
     * Numéro de téléphone : réservé à l'admin / super admin, et au commercial
     * qui détient **la réservation en cours** du client (client `RESERVED` /
     * `CONFIRMED` ET dernière réservation à son nom).
     *
     * Un client `AVAILABLE`, revenu `AVAILABLE` après un blocage temporaire,
     * ou `RESERVED` par un autre commercial : le numéro n'est tout simplement
     * pas envoyé (la clé est absente de la réponse, pas `null`).
     */
    private function canSeePhone(Client $client, ?Reservation $activeReservation): bool
    {
        $user = auth()->user();

        if ($user && in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return true;
        }

        if (! in_array($client->status, [Client::STATUS_RESERVED, Client::STATUS_CONFIRMED], true)) {
            return false;
        }

        return $activeReservation !== null
            && $activeReservation->comercial_id === $user?->id;
    }

    protected function formatClient(Client $client, bool $detailed = false, bool $canSeePhone = false): array
    {
        $name = $client->name ?? '—';
        $enterpriseName = $client->enterprise_name ?? '—';

        $base = [
            'id' => $client->id,
            'name' => $name,
            'email' => $client->email,
            // Clé absente si le connecté n'a pas le droit de voir le numéro
            // (cf. `canSeePhone()`) : le frontend n'affiche alors rien.
            ...($canSeePhone ? ['phone' => $client->phone] : []),
            'status' => $client->status,
            // Statut affiché (règle §2 : RESERVED qualifié par sa dernière
            // réservation) + retour éventuel du blocage temporaire.
            'display_status' => $client->displayStatus(),
            'is_blacklisted' => $client->is_blacklisted,
            'returned_at' => $client->returned_at,
            'municipality' => $client->municipality,
            'administrative_region' => $client->administrative_region,
            'neq' => $client->neq,
            'categories' => $client->categories,
            'respondents' => $client->respondents,
            'respondent_count' => $client->respondent_count,
            'authorized_categories' => $client->authorized_categories,
            'licence_number' => $client->licence_number,
            'licence_propre_numero' => $client->licence_propre_numero,
            'licence_status' => $client->licence_status,
            'licence_end_date' => $client->licence_end_date,
            'enterprise_name' => $enterpriseName,
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];

        if ($detailed) {
            $activeReservation = $this->latestReservationOf($client);

            // Réservation du connecté, seulement si elle est encore active :
            // c'est elle qui conditionne l'affichage du bouton « Suite appel ».
            $myReservation = $client->reservations
                ->where('comercial_id', auth()->id())
                ->sortByDesc(fn (Reservation $r) => [$r->created_at, $r->id])
                ->first();

            if ($myReservation
                && ! in_array($myReservation->status, Reservation::ACTIVE_STATUSES, true)) {
                $myReservation = null;
            }

            if ($myReservation
                && ! in_array($client->status, [Client::STATUS_RESERVED, Client::STATUS_CONFIRMED], true)) {
                $myReservation = null;
            }

            $assignedCommercial = $activeReservation?->comercial;

            // Délai d'affichage du rappel de la réservation de l'employé
            // (table `rappels`) : [montant, unité] ou [null, null].
            $rappelDelay = $myReservation?->rappel?->delay() ?? [null, null];

            $base = array_merge($base, [
                'neq' => $client->neq,
                'full_address' => $client->full_address,
                'categories' => $client->categories,
                'licence_propre' => $client->licence_propre,
                'intervenant_name' => $client->intervenant_name,
                'licence_start_date' => $client->licence_start_date,
                'respondent_count' => $client->respondent_count,
                'respondents' => $client->respondents,
                'sub_category_count' => $client->sub_category_count,
                'authorized_categories' => $client->authorized_categories,
                'surety_company' => $client->surety_company,
                'cautionnement_compagnie' => $client->cautionnement_compagnie,
                'surety_amount' => $client->surety_amount,
                'representative_name' => $client->representative_name,
                'assigned_comercial' => $assignedCommercial ? [
                    'id' => $assignedCommercial->id,
                    'email' => $assignedCommercial->email,
                    'first_name' => $assignedCommercial->first_name,
                    'last_name' => $assignedCommercial->last_name,
                ] : null,
                'reservations_count' => $client->reservations_count ?? $client->reservations()->count(),
                'notes_count' => $client->notes_count ?? $client->notes()->count(),
                'returned_at' => $client->returned_at,
                // Journal unique du client : issues d'appel, réservation,
                // listes noires et commentaires (plus de double source).
                'notes' => $client->notes->map(fn ($n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'description' => $n->description,
                    'created_at' => $n->created_at,
                    'sender' => $n->sender ? [
                        'id' => $n->sender->id,
                        'first_name' => $n->sender->first_name,
                        'last_name' => $n->sender->last_name,
                    ] : null,
                ]),
                'my_reservation' => $myReservation ? [
                    'id' => $myReservation->id,
                    'status' => $myReservation->status,
                    'bv_count' => $myReservation->bv_count,
                    'injoinable_count' => $myReservation->injoinable_count,
                    // Rappel : table `rappels`, délai recalculé à la volée.
                    'recall_at' => $myReservation->rappel?->reminder_date,
                    'recall_after' => $rappelDelay[0],
                    'recall_unit' => $rappelDelay[1],
                ] : null,
            ]);
        }

        return $base;
    }
}
