<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rappels de l'employé connecté, séparés par type de réservation :
 *
 *  - page « Rappels »      -> CALL_BACK seuls (rappel choisi par l'employé) ;
 *  - page « Auto-rappels » -> BV_VOICEMAIL seuls (rappel automatique à 3 jours).
 *
 * Source : table `rappels` (reminder_date), plus les colonnes de rappel de
 * `reservations` qui ont été supprimées. Seuls les rappels en attente
 * (`done_at IS NULL`) sont listés et comptés.
 */
class ReminderController extends Controller
{
    /** Type renvoyé quand `type` est absent : la page « Rappels » (CALL_BACK). */
    private const DEFAULT_TYPE = Reservation::STATUS_CALL_BACK;

    public function index(Request $request): JsonResponse
    {
        $type = $this->recallType($request);

        $rappels = $this->query($request, $type)
            ->with([
                'client:id,name,enterprise_name,status,phone,email,municipality,'
                    .'licence_number,neq,categories,respondents,is_blacklisted,returned_at,'
                    .'current_reservation_id,current_comercial_id',
                'reservation:id,status',
                'client.currentReservation:id,status',
            ])
            ->orderBy('reminder_date', 'asc')
            ->get();

        // Statut affiché (règle §2) : une seule requête pour toute la liste.
        Client::loadLatestReservations($rappels->pluck('client')->filter());

        $formatted = $rappels->map(function (Rappel $rappel) use ($type) {
            [$amount, $unit] = $rappel->delay();

            $client = $rappel->client;
            $reservationStatus = $rappel->reservation?->status;

            return [
                'id' => $rappel->id,
                'client_id' => $rappel->client_id,
                'client_name' => $client->name ?? '—',
                'client_phone' => $client->phone,
                'client_municipality' => $client->municipality,
                // --- Ligne obsolète : le bouton « Voir » est masqué si l'un
                //     de ces trois drapeaux est vrai. `done_at` et
                //     `status_changed` sont aussi renvoyés par sécurité, même
                //     si la requête les exclut déjà de la liste.
                'done_at' => $rappel->done_at,
                'status_changed' => $reservationStatus !== null && $reservationStatus !== $type,
                'has_newer_suivi' => $client
                    ? $client->notes()->where('created_at', '>', $rappel->created_at)->exists()
                    : false,
                // --- Prospect : mêmes colonnes que la page « Prospects ».
                'client_email' => $client->email,
                'enterprise_name' => $client->enterprise_name,
                'client_status' => $client->status,
                'display_status' => $client->displayStatus(),
                // Statut de la réservation **courante** du client : c'est lui
                // que la colonne « Statut » affiche (sauf client AVAILABLE /
                // liste noire → statut client), pour un vocabulaire commun à
                // toutes les listes.
                'reservation_status' => $client->currentReservation?->status,
                'is_blacklisted' => $client->is_blacklisted,
                'returned_at' => $client->returned_at,
                'licence_number' => $client->licence_number,
                'neq' => $client->neq,
                'categories' => $client->categories,
                'respondents' => $client->respondents,
                // --- Rappel.
                'status' => $reservationStatus,
                'recall_after' => $amount,
                'recall_unit' => $unit,
                'recall_at' => $rappel->reminder_date,
                'is_due' => $rappel->isDue(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $count = $this->query($request, $this->recallType($request))
            ->where('reminder_date', '<=', now())
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Marque un rappel comme terminé (« Terminer », note facultative).
     *
     * Le rappel quitte les listes (« Rappels » / « Auto-rappels ») et le
     * compteur du menu ; si une note est saisie, elle est écrite dans
     * l'historique du client (onglet « Historique » de `/prospects/:id`).
     * Même règle que toute note saisie à la main : 8 mots maximum.
     */
    public function done(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, $fail): void {
                    $words = preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);

                    if (is_array($words) && count($words) > Note::MAX_NOTE_WORDS) {
                        $fail('La note ne peut pas dépasser '.Note::MAX_NOTE_WORDS.' mots.');
                    }
                },
            ],
        ], [
            'note.max' => 'La note est trop longue.',
        ]);

        $note = trim((string) ($validated['note'] ?? ''));
        $user = $request->user();

        $rappel = Rappel::query()
            ->whereKey($id)
            ->where('comercial_id', $user->id)
            ->pending()
            ->first();

        if (! $rappel) {
            return response()->json([
                'success' => false,
                'message' => 'Rappel introuvable ou déjà terminé.',
            ], 404);
        }

        DB::transaction(function () use ($rappel, $user, $note): void {
            if ($note !== '') {
                Note::create([
                    'client_id' => $rappel->client_id,
                    'reservation_id' => $rappel->reservation_id,
                    'sender_id' => $user->id,
                    'type' => Note::TYPE_NOTE,
                    'description' => $note,
                ]);
            }

            $rappel->markAsDone($note !== '' ? $note : null);
        });

        return response()->json([
            'success' => true,
            'message' => 'Rappel terminé.',
            'data' => [
                'id' => $rappel->id,
                'client_id' => $rappel->client_id,
                'done_at' => $rappel->done_at,
                'done_note' => $rappel->done_note,
            ],
        ]);
    }

    /**
     * Rappels **en attente** de l'employé connecté, filtrés par type de
     * réservation — `$type` vient de `recallType()` qui valide l'entrée.
     */
    private function query(Request $request, string $type)
    {
        return Rappel::where('comercial_id', $request->user()->id)
            ->pending()
            ->whereHas('reservation', fn ($q) => $q->where('status', $type));
    }

    /**
     * Filtre de type : `?type=BV` (auto-rappels) ou `?type=CALL_BACK`
     * (défaut, page « Rappels »).
     */
    private function recallType(Request $request): string
    {
        $validated = $request->validate([
            // BV = alias court de BV_VOICEMAIL (ancienne valeur d'URL).
            'type' => 'sometimes|nullable|in:BV,'.Reservation::STATUS_BV_VOICEMAIL.','.Reservation::STATUS_CALL_BACK,
        ]);

        $type = $validated['type'] ?? self::DEFAULT_TYPE;

        return $type === 'BV' ? Reservation::STATUS_BV_VOICEMAIL : $type;
    }
}
