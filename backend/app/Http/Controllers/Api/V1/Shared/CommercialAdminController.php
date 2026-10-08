<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use App\Services\CallWorkflowService;
use App\Services\Client\ClientSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialAdminController extends Controller
{
    public function __construct(
        private CallWorkflowService $workflow,
        private ClientSearchService $search,
    ) {}

    public function index()
    {
        $users = User::where('role', 'COMERCIAL')->with('employee')->get();
        $data = $users->map(function ($u) {
            $res = Reservation::where('comercial_id', $u->id)->count();
            $calls = Note::where('sender_id', $u->id)->calls()->count();
            $callsOui = Note::where('sender_id', $u->id)->where('type', Note::TYPE_YES)->count();

            return [
                'id' => $u->id,
                'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '').' '.($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                'email' => $u->email,
                'reservations' => $res,
                'calls' => $calls,
                'calls_oui' => $callsOui,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, $id)
    {
        $user = User::where('role', 'COMERCIAL')->with('employee.enterprise')->findOrFail($id);
        $base = request()->getSchemeAndHttpHost();
        $ent = $user->employee?->enterprise;

        $groups = ReservationGroup::where('comercial_id', $id)->count();
        $reservations = Reservation::where('comercial_id', $id)->count();
        $calls = Note::where('sender_id', $id)->calls()->count();
        $callsOui = Note::where('sender_id', $id)->where('type', Note::TYPE_YES)->count();
        $clientsCalled = Note::where('sender_id', $id)->calls()->distinct()->count('client_id');
        $clientsOui = Note::where('sender_id', $id)->where('type', Note::TYPE_YES)->distinct()->count('client_id');

        $perPage = min((int) $request->input('per_page', 10), 300);
        $page = max((int) $request->input('page', 1), 1);

        // Périmètre de l'historique : les clients que **cet employé** a déjà
        // appelés (+ recherche texte). Reconstruit à chaque besoin pour que
        // les compteurs des badges partagent **exactement** la même base que
        // le tableau.
        $historyBase = function () use ($id, $request) {
            $query = Client::query()
                ->whereHas('notes', fn ($q) => $q->where('sender_id', $id)->calls());

            if ($search = $request->input('search')) {
                // Même recherche « toutes colonnes » + téléphone normalisé
                // que les Grande listes (ClientSearchService::search()).
                $this->search->search($query, $search);
            }

            return $query;
        };

        $historyQuery = $historyBase()->with([
            'notes' => fn ($q) => $q->where('sender_id', $id)->with('sender:id,first_name,last_name')->orderByDesc('created_at'),
            'currentReservation:id,status',
        ]);

        // Badges de la colonne « Statut » (RULES §9) : mêmes paramètres que
        // la grande liste admin — `status` / `reservation_status`, union OR.
        $this->applyStatusFilters($historyQuery, $request);

        $clientsPage = $historyQuery->paginate($perPage, ['*'], 'page', $page);

        // Compteurs des 10 badges : périmètre employé, calculés par les scopes
        // qui pilotent les filtres (compteur du badge = lignes après clic).
        // **Même forme que `data` de `GET clients/overview`**
        // (`{prospects, by_display_status}`) : la page les passe tels quels à
        // `<ProspectKpis counts={…}>`.
        $badges = [
            'prospects' => ['system' => $historyBase()->count()],
            'by_display_status' => $this->displayStatusCounts($historyBase),
        ];

        // Statut affiché : dernière réservation de chaque client en 1 requête.
        Client::loadLatestReservations($clientsPage->getCollection());

        $historique = $clientsPage->getCollection()->map(function ($c) {
            $last = $c->notes->first();

            return [
                'id' => $c->id,
                'name' => $c->name ?? '—',
                'email' => $c->email,
                'phone' => $c->phone,
                'status' => $c->status,
                'display_status' => $c->displayStatus(),
                // Statut de la réservation courante : valeur que la colonne
                // « Statut » affiche (sauf client AVAILABLE / liste noire).
                'reservation_status' => $c->currentReservation?->status,
                'is_blacklisted' => $c->is_blacklisted,
                'returned_at' => $c->returned_at,
                'municipality' => $c->municipality,
                'enterprise_name' => $c->enterprise_name ?? '—',
                'licence_end_date' => $c->licence_end_date,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
                'last_call' => $last ? [
                    'type' => $last->type,
                    'description' => $last->description,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        });

        return response()->json([
            'user' => $user,
            'analytics' => [
                'groups' => $groups,
                'reservations' => $reservations,
                'calls' => $calls,
                'calls_oui' => $callsOui,
                'clients_called' => $clientsCalled,
                'clients_oui' => $clientsOui,
            ],
            'employee' => [
                'prenom' => $user->employee?->first_name ?? $user->first_name,
                'nom' => $user->employee?->last_name ?? $user->last_name,
                'telephone' => $user->employee?->phone ?? $user->phone,
                'image_dp' => $user->employee?->image_dp,
                'image_dp_url' => $user->employee?->image_dp
                    ? "{$base}/storage/avatars/{$user->employee->image_dp}" : null,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'additional_info' => $user->employee?->additional_info,
                'cree_le' => $user->created_at,
            ],
            'entreprise' => $ent ? [
                'id' => $ent->id,
                'name' => $ent->name,
                'email' => $ent->email,
                'tax_number' => $ent->tax_number,
                'phone' => $ent->phone,
                'address' => $ent->address,
                'status' => $ent->status,
                'logo_url' => $ent->logo ? "{$base}/storage/logos/{$ent->logo}" : null,
            ] : null,
            'historique' => [
                'clients' => $historique,
                // Compteurs des 10 badges de statut, sur le périmètre de cet
                // employé (alimente `<ProspectKpis>` de l'onglet Historique).
                'badges' => $badges,
                'pagination' => [
                    'current_page' => $clientsPage->currentPage(),
                    'last_page' => $clientsPage->lastPage(),
                    'per_page' => $clientsPage->perPage(),
                    'total' => $clientsPage->total(),
                ],
            ],
        ]);
    }

    /**
     * Admin list of clients that have interaction history (call outcomes).
     * GET /commercials/clients
     */
    public function clients(Request $request)
    {
        // **Grande liste** du panel admin : tous les prospects de la base,
        // sans restriction (plus de condition « déjà appelé par un employé »,
        // ni de filtrage lié à une réservation). Le périmètre est donc
        // exactement celui de `prospects.system` (badge *Tous* de
        // `GET clients/overview`) : compteur du badge = lignes rendues.
        $query = Client::query()
            ->with([
                'notes' => fn ($q) => $q->with('sender:id,first_name,last_name')->orderByDesc('created_at'),
                // Pointeur de réservation courante : la colonne « Statut » en
                // déduit la valeur affichée (Oui / Non / BV / À rappeler / -).
                'currentReservation:id,status',
            ]);

        if ($search = $request->input('search')) {
            // Recherche « toutes colonnes » (§8) — voir ClientSearchService::search() :
            // téléphone indifféremment formaté, licence propre incluse.
            $this->search->search($query, $search);
        }

        // Aucun filtre de date : les champs « Du / Au » ont été supprimés.

        // Badges de la colonne « Statut » → deux paramètres serveur (§9).
        $this->applyStatusFilters($query, $request);

        // Filtres de listes (scopes Eloquent) : mêmes paramètres que la liste
        // commerciale — municipalité, catégorie et région administrative.
        if ($municipality = $request->input('municipality')) {
            $this->search->filterByMunicipalities($query, $municipality);
        }

        if ($category = $request->input('category')) {
            $this->search->filterByCategories($query, $category);
        }

        if ($region = $request->input('administrative_region')) {
            $this->search->filterByAdministrativeRegions($query, $region);
        }

        $sortable = [
            'name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'status' => 'status',
            'municipality' => 'municipality',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'licence_end_date' => 'licence_end_date',
        ];
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min((int) $request->input('per_page', 10), 300);
        $page = max((int) $request->input('page', 1), 1);
        $clientsPage = $query->paginate($perPage, ['*'], 'page', $page);

        // Statut affiché : dernière réservation de chaque client en 1 requête.
        Client::loadLatestReservations($clientsPage->getCollection());

        $clients = $clientsPage->getCollection()->map(function ($c) {
            $last = $c->notes->first();

            return [
                'id' => $c->id,
                'name' => $c->name ?? '—',
                'email' => $c->email,
                'phone' => $c->phone,
                'status' => $c->status,
                'display_status' => $c->displayStatus(),
                // Statut de la réservation courante (NULL si aucune) : la
                // colonne « Statut » l'affiche tel quel, sauf client
                // `AVAILABLE` (relisté) ou en liste noire → statut client.
                'reservation_status' => $c->currentReservation?->status,
                'current_comercial_id' => $c->current_comercial_id,
                'is_blacklisted' => $c->is_blacklisted,
                'returned_at' => $c->returned_at,
                'municipality' => $c->municipality,
                'neq' => $c->neq,
                'licence_number' => $c->licence_number,
                'categories' => $c->categories,
                'respondents' => $c->respondents,
                'respondent_count' => $c->respondent_count,
                'authorized_categories' => $c->authorized_categories,
                'enterprise_name' => $c->enterprise_name ?? '—',
                'source' => $c->source,
                'licence_end_date' => $c->licence_end_date,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
                'last_call' => $last ? [
                    'type' => $last->type,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'clients' => $clients,
                'pagination' => [
                    'current_page' => $clientsPage->currentPage(),
                    'last_page' => $clientsPage->lastPage(),
                    'per_page' => $clientsPage->perPage(),
                    'total' => $clientsPage->total(),
                ],
            ],
        ]);
    }

    /**
     * Badges de la colonne « Statut » → **deux paramètres serveur** (RULES §9).
     *
     *   - `status`             → statut **client** (Disponible / Blacklist),
     *     même définition que `by_status`, `ClientSearchService::filterByStatuses()` ;
     *   - `reservation_status` → statut de la **réservation courante**
     *     (Oui / Non / BV / À rappeler / « - »), c'est-à-dire la valeur que la
     *     colonne « Statut » affiche, `filterByReservationStatuses()`.
     *
     * Les deux jeux sont **disjoints** (un client `AVAILABLE` ou blacklisté
     * n'entre jamais dans la dimension réservation) et combinés en **union
     * `OR`** : la somme des compteurs vaut exactement le nombre de lignes
     * rendues.
     */
    private function applyStatusFilters(Builder $query, Request $request): Builder
    {
        return $this->search->applyDisplayStatusFilters(
            $query,
            $request->input('status'),
            $request->input('reservation_status'),
        );
    }

    /**
     * Compteurs des **10 badges** pour un périmètre donné — délégation à
     * `ClientSearchService::displayStatusCounts()` (partagé avec le détail d'une
     * entreprise).
     *
     * @param  callable(): Builder  $base
     * @return array<string, int>
     */
    private function displayStatusCounts(callable $base): array
    {
        return $this->search->displayStatusCounts($base);
    }

    /**
     * Admin view of a single client (read-only data + interactions).
     * GET /commercials/clients/{id}
     */
    public function client(Request $request, $id)
    {
        $client = Client::with(['reservations.comercial', 'notes.sender'])->find($id);
        if (! $client) {
            return response()->json(['message' => 'Client introuvable.'], 404);
        }

        $activeReservation = $client->reservations->sortByDesc('created_at')->first();
        $assigned = $activeReservation?->comercial;

        return response()->json([
            'success' => true,
            'data' => [
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name ?? '—',
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'status' => $client->status,
                    'display_status' => $client->displayStatus(),
                    'is_blacklisted' => $client->is_blacklisted,
                    'returned_at' => $client->returned_at,
                    'municipality' => $client->municipality,
                    'administrative_region' => $client->administrative_region,
                    'licence_number' => $client->licence_number,
                    'licence_propre_numero' => $client->licence_propre_numero,
                    'licence_status' => $client->licence_status,
                    'licence_end_date' => $client->licence_end_date,
                    'licence_start_date' => $client->licence_start_date,
                    'intervenant_name' => $client->intervenant_name,
                    'licence_propre' => $client->licence_propre,
                    'enterprise_name' => $client->enterprise_name ?? '—',
                    // Origine du prospect (répertoire `sources`).
                    'source' => $client->source,
                    'created_at' => $client->created_at,
                    'updated_at' => $client->updated_at,
                    'neq' => $client->neq,
                    'full_address' => $client->full_address,
                    'categories' => $client->categories,
                    'respondent_count' => $client->respondent_count,
                    'respondents' => $client->respondents,
                    'sub_category_count' => $client->sub_category_count,
                    'authorized_categories' => $client->authorized_categories,
                    'surety_company' => $client->surety_company,
                    'cautionnement_compagnie' => $client->cautionnement_compagnie,
                    'surety_amount' => $client->surety_amount,
                    'representative_name' => $client->representative_name,
                    'assigned_commercial' => $assigned ? [
                        'id' => $assigned->id,
                        'email' => $assigned->email,
                        'first_name' => $assigned->first_name,
                        'last_name' => $assigned->last_name,
                    ] : null,
                    'reservations_count' => $client->reservations->count(),
                    'notes_count' => $client->notes->count(),
                    'my_reservation' => null,
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
                ],
            ],
        ]);
    }

    /**
     * Ajout / modification du numéro de téléphone d'un client — accès
     * Admin / Super Admin (PATCH /commercials/clients/{id}/phone).
     *
     * Le statut « Sans téléphone » est **dérivé** de `phone` : renseigner un
     * numéro fait donc sortir le client du seau dans le même élan (le badge
     * et la liste sont invalidés côté client), une valeur vide le remet
     * dedans. La saisie est normalisée par `Client::cleanPhone()` (même
     * mise en forme que l'import n8n : `5143535820` → `514-353-5820`).
     */
    public function updatePhone(Request $request, $id)
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:40',
        ]);

        $client = Client::findOrFail($id);
        $phone = Client::cleanPhone($validated['phone'] ?? null);

        // Saisie manuelle : la fiche devient « modifiée à la main » — l'import
        // scraper / n8n ne la réécrit plus jamais (is_manually_updated = true,
        // lu par ClientImportService::upsertFromScraperPayload).
        $client->update([
            'phone' => $phone,
            'is_manually_updated' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => $phone === null ? 'Numéro retiré du client.' : 'Numéro de téléphone enregistré.',
            'data' => [
                'client' => [
                    'id' => $client->id,
                    'phone' => $client->phone,
                    'display_status' => $client->displayStatus(),
                    'is_blacklisted' => $client->is_blacklisted,
                    'returned_at' => $client->returned_at,
                    'municipality' => $client->municipality,
                ],
            ],
        ]);
    }

    /**
     * Admin blacklist of a client.
     * POST /commercials/clients/{id}/blacklist
     */
    public function blacklist(Request $request, $id)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:5000',
        ]);

        $client = Client::findOrFail($id);

        return DB::transaction(function () use ($client, $request, $validated) {
            // Même chemin que le commercial : le service applique le statut
            // `BLACKLISTED`, `is_blacklisted`, `returned_at = null`, annule
            // les rappels et journalise la note (émetteur = l'admin).
            $this->workflow->apply($client, null, Note::TYPE_BLACKLISTED, $validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Client mis en liste noire.',
            ]);
        });
    }
}
