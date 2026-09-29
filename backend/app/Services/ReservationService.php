<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cas 1 — création initiale d'une réservation (docs/RULES.md §7).
 *
 * Opération **unitaire et transactionnelle** :
 *
 *   1. vérification que le client **existe** et que son statut est bien
 *      `AVAILABLE` (re-vérifié **sous verrou** `lockForUpdate` : la
 *      sélection du lot n'est qu'une pré-sélection) ;
 *   2. création de la réservation (`PENDING`) liée au client, à l'employé
 *      et au groupe ;
 *   3. passage du client en `RESERVED` avec `returned_at` vidé ;
 *   4. note système `RESERVED` rattachée à la réservation créée
 *      (`sender_id = SYSTEM`).
 *
 * Échec d'une des vérifications → **aucune écriture** (rollback implicite) et
 * tableau `conflict` renvoyé, affiché dans le modal « Réserver des
 * prospects ». Les écritures sont regroupées dans la réponse (`reservations`,
 * `clients`, `notes`).
 */
class ReservationService
{
    /** Client introuvable (a disparu entre la sélection et le verrou). */
    public const REASON_NOT_FOUND = 'client_not_found';

    /** Statut actuel différent de `AVAILABLE` (exigence du cas 1). */
    public const REASON_NOT_AVAILABLE = 'client_not_available';

    /** Une réservation active existe déjà (la sienne ou celle d'un autre). */
    public const REASON_ALREADY_RESERVED = 'already_reserved';

    /** Erreur technique : transaction annulée, prospect signalé en conflit. */
    public const REASON_ERROR = 'error';

    /**
     * Réserve un client pour un groupe donné, dans une transaction unique.
     *
     * @return array{reservation?: Reservation, client?: Client, note?: Note, conflict?: array<string, mixed>}
     */
    public function reserveClient(string $clientId, User $actor, ReservationGroup $group): array
    {
        try {
            return DB::transaction(function () use ($clientId, $actor, $group) {
                $client = Client::whereKey($clientId)->lockForUpdate()->first();

                if (! $client) {
                    return $this->conflict($clientId, self::REASON_NOT_FOUND, 'Client introuvable.');
                }

                // 2. Validation du statut — sous verrou : seuls les clients
                //    AVAILABLE sont réservables.
                if ($client->status !== Client::STATUS_AVAILABLE) {
                    return $this->conflict(
                        $client->id,
                        self::REASON_NOT_AVAILABLE,
                        sprintf('Client non disponible : statut %s.', $client->status),
                        ['name' => $client->name, 'status' => $client->status]
                    );
                }

                // Client déjà tenu par une réservation active : signalé avec
                // l'employé propriétaire (affiché par le modal).
                $active = $client->reservations()->active()->latest('created_at')->first();
                if ($active) {
                    $owner = $active->comercial;

                    return $this->conflict(
                        $client->id,
                        self::REASON_ALREADY_RESERVED,
                        'Ce prospect est déjà réservé.',
                        [
                            'name' => $client->name,
                            'status' => $client->status,
                            'reserved_by' => $owner
                                ? (trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')) ?: $owner->email)
                                : null,
                            'reservation_status' => $active->status,
                        ]
                    );
                }

                // 3a. Réservation (PENDING) liée client + employé + groupe.
                $reservation = Reservation::create([
                    'client_id' => $client->id,
                    'comercial_id' => $actor->id,
                    'reservation_group_id' => $group->id,
                    'status' => Reservation::STATUS_PENDING,
                ]);

                // 3b. Statut du client + fin du compte à rebours éventuel.
                $client->update([
                    'status' => Client::STATUS_RESERVED,
                    'returned_at' => null,
                ]);

                // 3c. Note système rattachée à la réservation créée.
                $note = Note::create([
                    'client_id' => $client->id,
                    'reservation_id' => $reservation->id,
                    'sender_id' => Note::SENDER_SYSTEM,
                    'type' => Note::TYPE_RESERVED,
                    'description' => sprintf(
                        'Réservé par %s le %s',
                        trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: $actor->email,
                        now()->format('Y-m-d H:i:s')
                    ),
                ]);

                return [
                    'reservation' => $reservation,
                    'client' => $client->fresh(),
                    'note' => $note,
                ];
            });
        } catch (\Throwable $e) {
            // Transaction déjà annulée : on signale le conflit sans casser le lot.
            return $this->conflict($clientId, self::REASON_ERROR, $e->getMessage());
        }
    }

    /**
     * Conflit à afficher au même endroit que l'ancien tableau `conflicts`
     * (`error` = message affiché par le modal).
     *
     * @param  Client|string  $client
     * @return array{conflict: array<string, mixed>}
     */
    private function conflict($client, string $code, string $message, array $extra = []): array
    {
        $id = $client instanceof Client ? $client->id : $client;

        return [
            'conflict' => array_merge([
                'client_id' => $id,
                'code' => $code,
                'error' => $message,
                'message' => $message,
            ], $extra),
        ];
    }
}
