<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Reservation;
use App\Services\Client\ClientSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProspectOverviewController extends Controller
{
    public function __construct(private ClientSearchService $search) {}

    /**
     * GET /clients/overview — cartes KPI « Overview » des deux listes de
     * prospects (panel commercial et panel admin).
     *
     * Chiffres **globaux** (système entier), indépendants des filtres de la
     * liste : ce sont des totaux, pas un découpage de la page courante —
     * sauf `?source=` (onglet actif de la Grande liste admin), qui borne
     * **tous** les compteurs à la source demandée pour que l'invariant
     * « compteur du badge = lignes rendues » tienne sous chaque onglet
     * (`ClientSearchService::filterBySource()`, absent / vide = périmètre
     * entier).
     *
     * Définitions métier :
     *  - « traité » (`processed`) : le client porte au moins une issue
     *    d'appel (note `YES` / `NO` / `BV` / `CALL_BACK`) — le commercial qui
     *    l'a réservé a appelé et/ou fait évoluer son statut ;
     *  - « en cours » : traité mais encore **tenu** (`Client::HELD_STATUSES`
     *    : RESERVED / DOUBLE / INFO — BV / À rappeler / suite à donner) ;
     *  - « succès » : traité et `CONFIRMED` (issue « YES ») ;
     *  - « par statut » (`by_status`) : une entrée par statut **courant**
     *    (`AVAILABLE` / `RESERVED` / `CONFIRMED` / `UNAVAILABLE` /
     *    `BLACKLISTED`), pour les badges « Tous + 4 statuts » qui servent de
     *    filtre dans le panel admin. Chaque compteur a **exactement la même
     *    définition** que le filtre `status` de `GET commercials/clients`
     *    (une ligne blacklistée va dans `BLACKLISTED`, quel que soit son
     *    `status`) : le chiffre affiché coïncide avec le nombre de lignes
     *    renvoyées après clic. Les statuts sans badge (`DOUBLE` / `INFO`,
     *    régime « tenu », et les valeurs historiques hors
     *    `Client::STATUSES`) restent dans `prospects.system` (le total du
     *    badge « Tous »).
     *  - « par statut affiché » (`by_display_status`) : une entrée par valeur
     *    que la **colonne « Statut »** montre réellement — `AVAILABLE`
     *    (Libre), `BLACKLISTED` (Blacklist), `SANS_TELEPHONE` (Sans
     *    téléphone, seau dérivé d'un `phone` vide), puis le statut de la
     *    réservation **courante** `YES` / `NO` / `BV_VOICEMAIL` / `CALL_BACK`
     *    / `DOUBLE` / `INFO` / `PENDING` (« - »). Ce sont les 10 badges de
     *    filtre demandés ;
     *    chaque compteur est calculé **par le scope qui pilote le filtre**
     *    (`filterByStatuses()` / `filterByReservationStatuses()`), donc le
     *    chiffre affiché coïncide avec le nombre de lignes renvoyées après
     *    clic. Les jeux de la dimension réservation sont disjoints de celle
     *    du statut client (un client AVAILABLE ou blacklisté n'entre jamais
     *    dans la dimension réservation) — une sélection mêlant les deux
     *    dimensions est une union exacte. `SANS_TELEPHONE`, lui, **recoupe**
     *    les autres seaux client (sélection unique dans l'UI).
     *
     * Requêtes en direct (pas de cache) : les compteurs bougent à chaque
     * appel réservé, inutile de les figer une semaine comme les listes
     * distinctes des filtres.
     */
    public function overview(Request $request): JsonResponse
    {
        // ── 0. Périmètre « source » (onglets de la Grande liste admin) ──
        // Paramètre **vide ou absent = périmètre entier**. Quand un onglet
        // est actif, TOUS les compteurs suivent le filtre : l'invariant
        // « compteur du badge = lignes rendues après clic » (§9) doit tenir
        // sous chaque onglet, sinon les badges de statut mentiraient.
        $clients = fn (): Builder => $this->search->filterBySource(
            Client::query(),
            $request->input('source'),
        );

        // ── 1. Prospects ────────────────────────────────────────────────
        $system = $clients()->count();
        $blacklisted = $clients()->where('is_blacklisted', true)->count();
        $available = $clients()
            ->where('is_blacklisted', false)
            ->available()
            ->count();

        // ── 2/3. Réservés : traités / non traités ───────────────────────
        // « Réservé » au sens large = prospect **tenu** par un employé :
        // RESERVED, mais aussi DOUBLE / INFO (issues qui conservent la
        // réservation, régime `Client::HELD_STATUSES`).
        $reservedTotal = $clients()->whereIn('status', Client::HELD_STATUSES)->count();
        $reservedProcessed = $clients()
            ->whereIn('status', Client::HELD_STATUSES)
            ->whereHas('notes', fn ($q) => $q->calls())
            ->count();

        // ── 4. Traités : succès / en cours ──────────────────────────────
        $processedTotal = $clients()->whereHas('notes', fn ($q) => $q->calls())->count();
        $processedSuccess = $clients()
            ->whereHas('notes', fn ($q) => $q->calls())
            ->where('status', Client::STATUS_CONFIRMED)
            ->count();
        $processedInProgress = $clients()
            ->whereHas('notes', fn ($q) => $q->calls())
            ->whereIn('status', Client::HELD_STATUSES)
            ->count();

        // ── 5. Statuts (badges de filtre du panel admin) ─────────────
        // Un seul `GROUP BY` sur les clients non blacklistés : le compteur
        // de chaque statut a **exactement** la même définition que le filtre
        // `status` de `GET commercials/clients` (où `BLACKLISTED` filtre sur
        // `is_blacklisted`) — ainsi le chiffre du badge et le nombre de lignes
        // renvoyées après clic coïncident. Les lignes blacklistées, quel que
        // soit leur `status`, vont dans le seau `BLACKLISTED`.
        $statusCounts = $clients()
            ->where('is_blacklisted', false)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── 6. Statut affiché (les 10 badges de la colonne « Statut ») ───
        // Libre / Blacklist = dimension **client** ; Oui / Non / BV /
        // À rapp.. / Double / Info / - = dimension **réservation courante** ;
        // Sans
        // téléphone = seau **dérivé** (`phone` vide) qui recoupe les deux.
        // Les compteurs sont produits par les scopes mêmes qui filtrent la
        // liste, avec les mêmes paramètres que `GET commercials/clients`.
        $displayCounts = [];
        $clientBuckets = [
            Client::STATUS_AVAILABLE,
            Client::STATUS_BLACKLISTED,
            Client::STATUS_SANS_TELEPHONE,
        ];
        $reservationBuckets = [
            Reservation::STATUS_YES,
            Reservation::STATUS_NO,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK,
            Reservation::STATUS_DOUBLE,
            Reservation::STATUS_INFO,
            Reservation::STATUS_PENDING,
        ];

        foreach ($clientBuckets as $bucket) {
            $displayCounts[$bucket] = $this->search->filterByStatuses($clients(), $bucket)->count();
        }

        foreach ($reservationBuckets as $bucket) {
            $displayCounts[$bucket] = $this->search->filterByReservationStatuses($clients(), $bucket)->count();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'prospects' => [
                    'system' => $system,
                    'not_blacklisted' => $system - $blacklisted,
                    'blacklisted' => $blacklisted,
                    'available' => $available,
                ],
                'reserved' => [
                    'total' => $reservedTotal,
                    'processed' => $reservedProcessed,
                    'not_processed' => $reservedTotal - $reservedProcessed,
                ],
                'processed' => [
                    'total' => $processedTotal,
                    'success' => $processedSuccess,
                    'in_progress' => $processedInProgress,
                ],
                'by_status' => [
                    Client::STATUS_AVAILABLE => (int) ($statusCounts[Client::STATUS_AVAILABLE] ?? 0),
                    Client::STATUS_RESERVED => (int) ($statusCounts[Client::STATUS_RESERVED] ?? 0),
                    Client::STATUS_CONFIRMED => (int) ($statusCounts[Client::STATUS_CONFIRMED] ?? 0),
                    Client::STATUS_UNAVAILABLE => (int) ($statusCounts[Client::STATUS_UNAVAILABLE] ?? 0),
                    Client::STATUS_BLACKLISTED => $blacklisted,
                ],
                // Badges « Tous / Libre / Oui / Non / BV / À rapp.. /
                // Blacklist / Sans tel.. » : une entrée par valeur
                // affichée, comptée par le filtre qu'elle pilote (invariant
                // badge ⇄ lignes).
                'by_display_status' => $displayCounts,
            ],
        ]);
    }
}
