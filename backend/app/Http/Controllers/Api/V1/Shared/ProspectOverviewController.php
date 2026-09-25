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
     *    d'appel (`call_outcomes`) — le commercial qui l'a réservé a appelé
     *    et/ou fait évoluer son statut ;
     *  - « en cours » : traité mais encore `RESERVED` (BV / À rappeler /
     *    suite à donner) ;
     *  - « succès » : traité et `SUCCESS` (issue « OUI »).
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
        $reservedTotal = Client::query()->where('status', 'RESERVED')->count();
        $reservedProcessed = Client::query()
            ->where('status', 'RESERVED')
            ->whereHas('callOutcomes')
            ->count();

        // ── 4. Traités : succès / en cours ──────────────────────────────
        $processedTotal = Client::query()->whereHas('callOutcomes')->count();
        $processedSuccess = Client::query()
            ->whereHas('callOutcomes')
            ->where('status', 'SUCCESS')
            ->count();
        $processedInProgress = Client::query()
            ->whereHas('callOutcomes')
            ->where('status', 'RESERVED')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'prospects' => [
                    'system'          => $system,
                    'not_blacklisted' => $system - $blacklisted,
                    'blacklisted'     => $blacklisted,
                    'available'       => $available,
                ],
                'reserved' => [
                    'total'         => $reservedTotal,
                    'processed'     => $reservedProcessed,
                    'not_processed' => $reservedTotal - $reservedProcessed,
                ],
                'processed' => [
                    'total'       => $processedTotal,
                    'success'     => $processedSuccess,
                    'in_progress' => $processedInProgress,
                ],
            ],
        ]);
    }
}
