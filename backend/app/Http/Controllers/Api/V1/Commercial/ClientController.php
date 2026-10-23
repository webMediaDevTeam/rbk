<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Services\CallWorkflowService;
use App\Services\Client\ClientSearchService;
use App\Services\ClientUpdateNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function __construct(
        private CallWorkflowService $workflow,
        private ClientSearchService $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Périmètre de la page, **reconstruit à l'identique** pour chaque
        // compteur de badge (un `Builder` ne se clone pas proprement entre
        // deux `count()`, d'où le `callable`) :
        //
        // Filtres + exclusions + tri **partagés** avec la réservation d'un
        // lot (`ClientSearchService::applyFilters`) : la page et le lot
        // réservé décrivent exactement les mêmes clients, dans le même ordre.
        // `$heldByUserId` = connecté : les prospects « Double » / « Info »
        // ne sont rendus qu'à leur titulaire (le lot, lui, ne les voit pas).
        //
        // Périmètre « source » : la Grande liste du commercial est
        // **verrouillée** sur la source de son entreprise — sans entreprise
        // ou sans source, liste vide (jamais de repli vers toute la base).
        // Un paramètre `source` éventuel est ignoré, le serveur décide.
        //
        // Privilège commercial (§7.1) : ne porte **que** sur la « libération
        // de la liste » (`POST reservations/release-pending`) — les prospects
        // « Double » restent visibles par **tous** les commerciaux, y compris
        // sans `has_permission`.
        $perimeter = function () use ($request, $user): Builder {
            return $this->search->applyCommercialSourceScope(
                // `$includeRepete` : la pastille « num Répétés » de la
                // barre rend ses lignes ; le lot réservé (`reserver`)
                // passe par le même `applyFilters` **sans** ce drapeau.
                $this->search->applyFilters(Client::query(), $request->all(), $user?->id, includeRepete: true),
                $user
            );
        };

        // Badge de statut cliqué (§9) : s'ajoute au périmètre, ne le remplace
        // jamais.
        $query = $this->applyBadgeFilter($perimeter(), $request);

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
                // Barre de badges « Tous / Libre / Double / Info / num Répétés »
                // de la page : même **forme** que `data` de `GET clients/overview`
                // (le commercial n'en consomme que ces clés).
                'badges' => [
                    'prospects' => ['system' => $perimeter()->count()],
                    'by_display_status' => [
                        Client::STATUS_AVAILABLE => $this->search->filterByStatuses($perimeter(), Client::STATUS_AVAILABLE)->count(),
                        Client::STATUS_DOUBLE => $this->search->filterByStatuses($perimeter(), Client::STATUS_DOUBLE)->count(),
                        Client::STATUS_INFO => $this->search->filterByStatuses($perimeter(), Client::STATUS_INFO)->count(),
                        Client::STATUS_AUTRE => $this->search->filterByStatuses($perimeter(), Client::STATUS_AUTRE)->count(),
                        Client::STATUS_REPETE => $this->search->filterByStatuses($perimeter(), Client::STATUS_REPETE)->count(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Filtre de la barre de badges de la Grande liste **commerciale** :
     * paramètre `status` ∈ { `AVAILABLE`, `DOUBLE`, `INFO`, `REPETE` } —
     * sélection unique ; absente ou inconnue, toute autre valeur laisse la
     * liste inchangée. S'appuie sur le statut **client** : dans cette liste, un
     * « Double » / « Info » affiché est toujours un prospect tenu par
     * l'employé connecté (`applyFilters()` branche `current_comercial_id`),
     * compteur et lignes rendues proviennent donc de la même colonne —
     * invariant « compteur = lignes » (§9).
     *
     * La réservation d'un lot (`POST clients/reserver`) **n'utilise pas ce
     * filtre** : elle reste sur tout le périmètre (un « Double » n'est jamais
     * réservable une seconde fois, un lot « Double » serait vide).
     */
    private function applyBadgeFilter(Builder $query, Request $request): Builder
    {
        $status = $request->input('status');

        $allowed = [Client::STATUS_AVAILABLE, Client::STATUS_DOUBLE, Client::STATUS_INFO, Client::STATUS_AUTRE, Client::STATUS_REPETE];

        if (! is_string($status) || ! in_array($status, $allowed, true)) {
            return $query;
        }

        return $this->search->filterByStatuses($query, $status);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Client::query()
            ->whereHas('reservations', function ($q) use ($user) {
                $q->where('comercial_id', $user->id);
            });

        // Le privilège commercial (§7.1) ne concerne **que** la libération de
        // la liste : les « Double » restent visibles par tous dans « Mes listes ».
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
        $client = Client::with(['reservations.comercial', 'reservations.rappel', 'notes.sender', 'parent'])
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

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'enterprise_name' => 'sometimes|nullable|string|max:255',
            'phone' => 'sometimes|nullable|string|max:40',
            'email' => 'sometimes|nullable|email|max:255',
            'representative_name' => 'sometimes|nullable|string|max:255',
            'full_address' => 'sometimes|nullable|string|max:2000',
            'municipality' => 'sometimes|nullable|string|max:255',
            'administrative_region' => 'sometimes|nullable|string|max:255',
            'neq' => 'sometimes|nullable|string|max:255',
            'licence_number' => 'sometimes|nullable|string|max:255',
            'licence_propre_numero' => 'sometimes|nullable|integer|min:0',
            'licence_propre' => 'sometimes|boolean',
            'licence_status' => 'sometimes|nullable|string|max:255',
            'licence_start_date' => 'sometimes|nullable|date',
            'licence_end_date' => 'sometimes|nullable|date',
            'respondent_count' => 'sometimes|nullable|integer|min:0',
            'respondents' => 'sometimes|nullable|array',
            'sub_category_count' => 'sometimes|nullable|integer|min:0',
            'categories' => 'sometimes|nullable|array',
            'authorized_categories' => 'sometimes|nullable|array',
            'surety_company' => 'sometimes|nullable|string|max:255',
            'cautionnement_compagnie' => 'sometimes|nullable|array',
            'surety_amount' => 'sometimes|nullable|numeric|min:0',
            // Note libre multi-lignes de la modale « Modifier le client » —
            // retours à la ligne **conservés** (hors liste `cleanText`).
            'note_personnalise' => 'sometimes|nullable|string|max:5000',
        ]);

        $client = Client::findOrFail($id);
        $user = $request->user();

        // Mise à jour **sans restriction d'appelant** : n'importe quel
        // utilisateur authentifié peut éditer n'importe quelle fiche (le
        // contrôle « réservation active du titulaire » a été retiré).

        foreach ([
            'name', 'enterprise_name', 'email', 'representative_name', 'full_address',
            'municipality', 'administrative_region', 'neq', 'licence_number', 'licence_status',
            'surety_company',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = Client::cleanText($validated[$field]);
            }
        }

        if (array_key_exists('phone', $validated)) {
            $validated['phone'] = Client::cleanPhone($validated['phone']);
        }

        if (array_key_exists('authorized_categories', $validated)) {
            $validated['categories'] = $validated['authorized_categories'];
        }

        $before = $client->getOriginal();
        $client->fill($validated);
        $client->is_manually_updated = true;
        $client->save();

        // Note « Mise à jour » : diff champ par champ, émetteur = l'éditeur.
        ClientUpdateNote::log($client, $user, $before);

        $client->load(['reservations.comercial', 'reservations.rappel', 'notes.sender']);

        return response()->json([
            'success' => true,
            'message' => 'Fiche client enregistrée.',
            'data' => [
                'client' => $this->formatClient($client, true, true),
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
            $this->workflow->apply($client, null, Note::TYPE_BLACKLISTED, $validated, $user);

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }

    private function latestReservationOf(Client $client): ?Reservation
    {
        if ($client->relationLoaded('latestReservation')) {
            return $client->latestReservation;
        }

        return $client->reservations
            ->sortByDesc(fn (Reservation $r) => [$r->created_at, $r->id])
            ->first();
    }

    private function canSeePhone(Client $client, ?Reservation $activeReservation): bool
    {
        $user = auth()->user();

        if ($user && in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return true;
        }

        if (! in_array($client->status, Client::HELD_STATUSES, true)) {
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
            ...($canSeePhone ? ['phone' => $client->phone] : []),
            'status' => $client->status,
            'display_status' => $client->displayStatus(),
            // Rattachement doublon : la colonne est toujours lisible ; le résumé
            // `parent` n'est rendu que si la relation est préchargée (fiche
            // détaillée) — jamais en liste, pour éviter un N+1.
            'parent_client_id' => $client->parent_client_id,
            'parent' => $client->relationLoaded('parent') && $client->parent ? [
                'id' => $client->parent->id,
                'name' => $client->parent->name,
                'phone' => $client->parent->phone,
            ] : null,
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
            // Origine du prospect (répertoire `sources`, défaut « Affaire ») :
            // renseignée par le payload n8n, jamais éditable en UI.
            'source' => $client->source,
            // Note libre multi-lignes (modale « Modifier le client ») : une
            // ligne = un élément de liste à l'affichage.
            'note_personnalise' => $client->note_personnalise,
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
                && ! in_array($client->status, Client::HELD_STATUSES, true)) {
                $myReservation = null;
            }

            $assignedCommercial = $activeReservation?->comercial;
            $hasOwnedReservation = $client->reservations->contains(
                fn (Reservation $reservation) => (string) $reservation->comercial_id === (string) auth()->id()
            );

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
                'notes' => $hasOwnedReservation ? $client->notes->map(fn ($n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'description' => $n->description,
                    'created_at' => $n->created_at,
                    'sender' => $n->sender ? [
                        'id' => $n->sender->id,
                        'first_name' => $n->sender->first_name,
                        'last_name' => $n->sender->last_name,
                        // Repli d'affichage de la frise (`senderName`) pour
                        // les utilisateurs sans prénom / nom.
                        'email' => $n->sender->email,
                    ] : null,
                ]) : [],
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
