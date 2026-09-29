<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

class ProspectOverviewController extends Controller
{
    /**
     * GET /clients/overview — cartes KPI « Overview » des deux listes de
     * prospects (panel commercial et panel admin).
     *
     * Chiffres **globaux** (système entier), indépendants des filtres de la
     * liste : ce sont des totaux, pas un découpage de la page courante.
     *
     * Définitions métier :
     *  - « traité » (`processed`) : le client porte au moins une issue
     *    d'appel (note `YES` / `NO` / `BV` / `CALL_BACK`) — le commercial qui
     *    l'a réservé a appelé et/ou fait évoluer son statut ;
     *  - « en cours » : traité mais encore `RESERVED` (BV / À rappeler /
     *    suite à donner) ;
     *  - « succès » : traité et `CONFIRMED` (issue « YES ») ;
     *  - « par statut » (`by_status`) : une entrée par statut **courant**
     *    (`AVAILABLE` / `RESERVED` / `CONFIRMED` / `UNAVAILABLE` /
     *    `BLACKLISTED`), pour les badges « Tous + 4 statuts » qui servent de
     *    filtre dans le panel admin. Chaque compteur a **exactement la même
     *    définition** que le filtre `status` de `GET commercials/clients`
     *    (une ligne blacklistée va dans `BLACKLISTED`, quel que soit son
     *    `status`) : le chiffre affiché coïncide avec le nombre de lignes
     *    renvoyées après clic. Les éventuels statuts historiques hors
     *    `Client::STATUSES` sortent des badges, mais restent dans
     *    `prospects.system` (le total du badge « Tous »).
     *
     * Requêtes en direct (pas de cache) : les compteurs bougent à chaque
     * appel réservé, inutile de les figer une semaine comme les listes
     * distinctes des filtres.
     */
    public function overview(): JsonResponse
    {
        // ── 1. Prospects ────────────────────────────────────────────────
        $system = Client::query()->count();
        $blacklisted = Client::query()->where('is_blacklisted', true)->count();
        $available = Client::query()
            ->where('is_blacklisted', false)
            ->available()
            ->count();

        // ── 2/3. Réservés : traités / non traités ───────────────────────
        $reservedTotal = Client::query()->where('status', Client::STATUS_RESERVED)->count();
        $reservedProcessed = Client::query()
            ->where('status', Client::STATUS_RESERVED)
            ->whereHas('notes', fn ($q) => $q->calls())
            ->count();

        // ── 4. Traités : succès / en cours ──────────────────────────────
        $processedTotal = Client::query()->whereHas('notes', fn ($q) => $q->calls())->count();
        $processedSuccess = Client::query()
            ->whereHas('notes', fn ($q) => $q->calls())
            ->where('status', Client::STATUS_CONFIRMED)
            ->count();
        $processedInProgress = Client::query()
            ->whereHas('notes', fn ($q) => $q->calls())
            ->where('status', Client::STATUS_RESERVED)
            ->count();

        // ── 5. Statuts (badges de filtre du panel admin) ─────────────
        // Un seul `GROUP BY` sur les clients non blacklistés : le compteur
        // de chaque statut a **exactement** la même définition que le filtre
        // `status` de `GET commercials/clients` (où `BLACKLISTED` filtre sur
        // `is_blacklisted`) — ainsi le chiffre du badge et le nombre de lignes
        // renvoyées après clic coïncident. Les lignes blacklistées, quel que
        // soit leur `status`, vont dans le seau `BLACKLISTED`.
        $statusCounts = Client::query()
            ->where('is_blacklisted', false)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

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
            ],
        ]);
    }
}
