<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $workflow) {}

    /**
     * Réservations du commercial connecté (compteur header) + garde de
     * traitement : `pending` = réservations encore `PENDING` (prospects non
     * traités) et `can_reserve` = `pending === 0`.
     */
    public function activeCount(Request $request): JsonResponse
    {
        $active = fn () => Reservation::active()
            ->where('comercial_id', $request->user()->id);

        $pending = $active()
            ->where('status', Reservation::STATUS_PENDING)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $active()->count(),
                'pending' => $pending,
                'can_reserve' => $pending === 0,
            ],
        ]);
    }

    /**
     * Réserve un lot de clients — cas 1 : création initiale d'une
     * réservation (docs/RULES.md §7).
     *
     * Par client, dans une transaction unique (`ReservationService`):
     *   1. le client existe et est `AVAILABLE` (re-vérifié sous verrou) ;
     *   2. réservation `PENDING` liée client + employé + groupe ;
     *   3. client `RESERVED`, `returned_at` vidé ;
     *   4. note système `RESERVED` rattachée à la réservation.
     *
     * Le lot est pré-sélectionné avec **la même requête que la page
     * Prospects** (`Client::scopeProspectList`) : mêmes filtres, mêmes
     * exclusions, même tri que ce que l'employé voit à l'écran.
     *
     * Garde de traitement : `409 unfinished_treatment` tant que l'employé a
     * des réservations encore `PENDING` (prospects non traités) — il doit
     * finir ses listes avant d'en ouvrir une nouvelle.
     *
     * Réponse `201` : groupe, compteurs (`requested` / `reserved` /
     * `conflicts`), conflits détaillés, réservations créées, état des
     * clients mis à jour et notes système créées.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Anciennes entrées inchangées : réservation sans nom (le champ
            // n'est plus demandé à l'utilisateur).
            'group_name' => 'nullable|string|max:255',
            // Nombre de prospects : 200 / 250 / 300 uniquement, pas de nombre libre.
            'count' => 'required|integer|in:200,250,300',
            // Nouvelles entrées optionnelles — identiques à `GET /clients` :
            // le lot doit suivre les filtres et le tri de la page.
            'search' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'administrative_region' => 'nullable|string|max:255',
            'sort_by' => 'nullable|string|in:'.implode(',', array_keys(Client::SORTABLE)),
            'sort_order' => 'nullable|in:asc,desc',
        ]);

        $count = (int) $validated['count'];
        $groupName = trim((string) ($validated['group_name'] ?? ''));
        if ($groupName === '') {
            $groupName = sprintf('Liste du %s', now()->format('d/m/Y H:i'));
        }
        $user = $request->user();

        // Garde de traitement (docs/RULES.md §7.1) : tant que l'employé a des
        // réservations encore `PENDING` (prospects non traités) dans ses
        // listes, il doit les finir avant d'ouvrir un nouveau lot — `409`.
        // Seules les réservations *actives* (client toujours RESERVED ou
        // CONFIRMED) comptent : un client noirci ou libéré par l'admin ne
        // peut pas être traité et ne doit pas le bloquer indéfiniment.
        $pending = Reservation::active()
            ->where('comercial_id', $user->id)
            ->where('status', Reservation::STATUS_PENDING)
            ->count();

        if ($pending > 0) {
            return response()->json([
                'success' => false,
                'error' => 'unfinished_treatment',
                'pending' => $pending,
                'message' => "Vous avez {$pending} prospect(s) non traité(s) dans vos listes : terminez-les avant de réserver.",
            ], 409);
        }

        $group = ReservationGroup::create([
            'comercial_id' => $user->id,
            'name' => $groupName,
            'total' => $count,
            'reserved_count' => 0,
        ]);

        // Pré-sélection = requête de la page Prospects (filtres + exclusions
        // + tri) complétée des règles métier de réservation : pas de
        // prospect déjà traité en NO / BV par cet employé.
        $candidates = Client::query()
            ->prospectList($validated)
            ->whereDoesntHave('notes', fn ($q) => $q
                ->where('sender_id', $user->id)
                ->whereIn('type', [Note::TYPE_NO, Note::TYPE_BV]))
            ->limit($count * 3)
            ->get();

        $reserved = 0;
        $conflicts = [];
        $reservations = [];
        $updatedClients = [];
        $notes = [];

        foreach ($candidates as $candidate) {
            if ($reserved >= $count) {
                break;
            }

            $result = $this->workflow->reserveClient($candidate->id, $user, $group);

            if (isset($result['conflict'])) {
                $conflicts[] = $result['conflict'];

                continue;
            }

            $reservations[] = $result['reservation'];
            $updatedClients[] = $result['client'];
            $notes[] = $result['note'];
            $reserved++;
        }

        // Statut affiché (dérivé de la dernière réservation) en **une seule**
        // requête pour tout le lot.
        Client::loadLatestReservations($updatedClients);
        $clients = array_map(fn (Client $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'returned_at' => $c->returned_at,
            'display_status' => $c->displayStatus(),
        ], $updatedClients);

        $group->update(['reserved_count' => $reserved]);

        return response()->json([
            'success' => true,
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'total' => $group->total,
                    'reserved_count' => $reserved,
                ],
                'requested' => $count,
                'reserved' => $reserved,
                // Compteurs « d'erreur » : demandés avec l'erreur quand le
                // statut du client n'est pas AVAILABLE.
                'counts' => [
                    'requested' => $count,
                    'candidates' => $candidates->count(),
                    'reserved' => $reserved,
                    'conflicts' => count($conflicts),
                ],
                'conflicts' => $conflicts,
                'reservations' => $reservations,
                'clients' => $clients,
                'notes' => $notes,
            ],
        ], 201);
    }
}
