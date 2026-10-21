<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Services\Client\ClientSearchService;
use App\Support\PeriodFilter;
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
     * Chiffres **globaux par défaut**, mais **alignés sur les filtres de la
     * liste** quand celle en fournit (`search`, `municipality`, `category`,
     * `administrative_region`, `source`, `employee_id`) — l'invariant
     * « compteur du badge = lignes rendues après clic » (§9) doit tenir
     * **sous filtres**. Le statut n'est pas un paramètre d'entrée : chaque
     * badge le ajoute lui-même, et « Entreprise » est ignoré (il ne borne que
     * la liste déroulante « Employé » côté UI). Voir `applyScopeFilters()`.
     *
     * Période **optionnelle** (`year` / `month` / `week` / `day`, mêmes règles
     * que le tableau de bord — `PeriodFilter`) : posée, elle ne borne **que**
     * les tuiles d'issue « Oui → Blacklist » (Oui / Non / BV / À rappeler /
     * Double / Info / Blacklist), sur la **date de la note du client** (la note
     * d'issue du type correspondant, créée dans `[début, fin)`). « Tous »,
     * « Libre » et « Sans tel. » — comme tous les compteurs de résumé —
     * restent des **instantanés fixes**, la période ne les scope pas. **Aucun**
     * paramètre de période = instantané complet — comportement historique des
     * badges de la liste, inchangé.
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
        // ── 0. Périmètre « filtres de la liste » ──────────────────────────
        // Paramètres **vides ou absents = périmètre entier**. Quand la Grande
        // liste applique des filtres (recherche, facettes, onglet « source »,
        // employé), TOUS les compteurs les suivent : l'invariant « compteur du
        // badge = lignes rendues après clic » (§9) doit tenir **sous filtres**,
        // sinon les badges de statut mentiraient. **Sans** le filtre « Entreprise »
        // — celui-ci ne borne que la liste déroulante « Employé » côté UI et ne
        // filtre pas les clients.
        // Période du dashboard (`?year`/`?month`/`?week`/`?day`) : absente =
        // instantané complet (badges de la liste) ; posée, elle borne UNIQUEMENT
        // les tuiles d'issue « Oui → Blacklist », sur la **date de la note** du
        // client. « Tous » / « Libre » / « Sans tel. » et les compteurs de résumé
        // restent instantanés — la période ne les scope pas.
        $period = PeriodFilter::fromRequest($request, defaultToCurrentYear: false);
        [$start, $end] = $period !== null ? PeriodFilter::bounds($period) : [null, null];

        /**
         * Périmètre « filtres de la liste » : **toujours un instantané** (aucune
         * borne de période ici) — base de « Tous », « Libre », « Sans tel. » et
         * de tous les compteurs de résumé.
         *
         * @var \Closure(): Builder<Client> $clients
         */
        $clients = fn (): Builder => $this->applyScopeFilters(Client::query(), $request);

        /* Ramène une requête de seau d'issue dans la fenêtre, sur la **date de
         * la note** du client : note d'issue du type correspondant créée dans
         * `[start, end)`. Aucun effet sans période, ni pour un seau hors liste
         * (Tous / Libre / Sans tel. / PENDING) — ils restent fixes. */
        $issueNoteTypes = [
            Reservation::STATUS_YES => Note::TYPE_YES,
            Reservation::STATUS_NO => Note::TYPE_NO,
            Reservation::STATUS_BV_VOICEMAIL => Note::TYPE_BV,
            Reservation::STATUS_CALL_BACK => Note::TYPE_CALL_BACK,
            Reservation::STATUS_DOUBLE => Note::TYPE_DOUBLE,
            Reservation::STATUS_INFO => Note::TYPE_INFO,
            Client::STATUS_BLACKLISTED => Note::TYPE_BLACKLISTED,
        ];
        $withinPeriod = function (Builder $query, string $bucket) use ($period, $start, $end, $issueNoteTypes): Builder {
            if ($period === null || ! isset($issueNoteTypes[$bucket])) {
                return $query;
            }

            $noteType = $issueNoteTypes[$bucket];

            return $query->whereHas('notes', fn ($n) => $n
                ->where('type', $noteType)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end));
        };

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
            $displayCounts[$bucket] = $withinPeriod(
                $this->search->filterByStatuses($clients(), $bucket),
                $bucket,
            )->count();
        }

        foreach ($reservationBuckets as $bucket) {
            $displayCounts[$bucket] = $withinPeriod(
                $this->search->filterByReservationStatuses($clients(), $bucket),
                $bucket,
            )->count();
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

    /**
     * Périmètre **hors statut** des badges : les filtres de la Grande liste que
     * les compteurs doivent suivre — recherche « toutes colonnes », facettes
     * (municipalité / catégorie / région), onglet « source » et « Employé ».
     * Exactement les critères de la liste (`CommercialAdminController::clients()`),
     * **sauf** le statut : chaque badge ajoute le sien via
     * `filterByStatuses()` / `filterByReservationStatuses()`.
     *
     * « Entreprise » volontairement absent : il ne borne que la liste déroulante
     * « Employé » côté UI et ne filtre pas les clients (donc pas les compteurs).
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private function applyScopeFilters(Builder $query, Request $request): Builder
    {
        if ($search = $request->input('search')) {
            $this->search->search($query, $search);
        }

        if ($municipality = $request->input('municipality')) {
            $this->search->filterByMunicipalities($query, $municipality);
        }

        if ($category = $request->input('category')) {
            $this->search->filterByCategories($query, $category);
        }

        if ($region = $request->input('administrative_region')) {
            $this->search->filterByAdministrativeRegions($query, $region);
        }

        $this->search->filterBySource($query, $request->input('source'));
        $this->search->filterByInteractionEmployee($query, $request->input('employee_id'));

        return $query;
    }
}
