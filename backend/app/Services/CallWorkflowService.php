<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Workflow d'appel (voir docs/RULES.md §3) — vocabulaire du modèle
 * (docs/models.puml) :
 *
 *  - YES         -> client CONFIRMED, réservation YES, rappel annulé.
 *                   Maintenue jusqu'à clôture admin.
 *  - NO          -> client UNAVAILABLE, returned_at = now + 3 mois,
 *                   réservation NO + vérification d'auto-blacklist.
 *  - BV          -> le client reste RESERVED, bv_count++, rappel automatique
 *                   à 3 jours ; 3e tentative (BV_ATTEMPTS_LIMIT) ->
 *                   UNAVAILABLE 21 jours + note automatique de l'employé.
 *  - CALL_BACK   -> injoinable_count++ et rappel planifié par l'employé, mais
 *                   **sans limite** : il peut enchaîner autant de rappels
 *                   qu'il veut (CALL_BACK_ATTEMPTS_LIMIT = null).
 *  - BLACKLISTED -> client BLACKLISTED.
 *
 * Chaque issue est journalisée dans `notes` (type = événement, description =
 * la note de 8 mots max, sender_id = l'employé). Un seul rappel par
 * réservation, stocké dans `rappels` (jamais dans la réservation).
 *
 * Plus aucune expiration de réservation et aucune libération manuelle :
 * seul le déblocage admin ne supprime rien — il vide les réservations.
 */
class CallWorkflowService
{
    /** Rappel automatique par défaut (BV : 3 jours ; repli CALL_BACK). */
    public const RECALL_DAYS = 3;

    /** Indisponibilité après NO. */
    public const NON_BLOCK_MONTHS = 3;

    /** Indisponibilité après NO (3 mois) ou après épuisement des BV (21 jours). */
    public const TEMP_BLOCK_DAYS = 21;

    /**
     * BV : seuil de tentatives avant `UNAVAILABLE` 21 jours — le 3e voicemail.
     */
    public const BV_ATTEMPTS_LIMIT = 3;

    /**
     * « À rappeler » : **aucune limite** — le commercial peut toujours
     * re-rappeler, le compteur `injoinable_count` est informatif seul.
     */
    public const CALL_BACK_ATTEMPTS_LIMIT = null;

    /**
     * Note d'épuisement des BV (8 mots max : l'émetteur est un employé, la
     * limite de saisie s'applique comme pour toute note d'issue).
     */
    public const AUTO_BLOCK_DESCRIPTION = 'Auto indisponible après 3 BV, retour 21 j';

    /**
     * Applique une issue d'appel sur un client et retourne le message métier.
     *
     * @param  array{note?: ?string, recall_at?: ?string}  $validated  `recall_at` :
     *                                                                 date/heure du rappel pour CALL_BACK (ignoré pour les autres).
     * @param  string  $event  un des `Note::TYPE_*` d'appel (YES/NO/BV/CALL_BACK/BLACKLISTED)
     * @return array{message: string, client_status: string, blacklisted: bool}
     */
    public function apply(Client $client, ?Reservation $reservation, string $event, array $validated, User $actor): array
    {
        $handled = [
            Note::TYPE_YES,
            Note::TYPE_NO,
            Note::TYPE_BV,
            Note::TYPE_CALL_BACK,
            Note::TYPE_BLACKLISTED,
        ];

        // Refus AVANT toute écriture : un événement inconnu ne doit laisser
        // aucune trace dans le journal.
        if (! in_array($event, $handled, true)) {
            throw new \InvalidArgumentException("Evénement non géré : {$event}");
        }

        return DB::transaction(function () use ($client, $reservation, $event, $validated, $actor) {
            $note = trim((string) ($validated['note'] ?? ''));

            // Journalisation AVANT l'application : la limite de 8 mots est
            // levée par le modèle, la transaction annule tout (422, rien écrit).
            Note::create([
                'client_id' => $client->id,
                // Rattachée à la réservation que l'événement fait bouger
                // (YES, NO, BV, CALL_BACK) — colonne nullable.
                'reservation_id' => $reservation?->id,
                'sender_id' => $actor->id,
                'type' => $event,
                'description' => $note === '' ? null : $note,
            ]);

            return match ($event) {
                Note::TYPE_YES => $this->handleYes($client, $reservation),
                Note::TYPE_NO => $this->handleNo($client, $reservation, $actor),
                Note::TYPE_BV => $this->handleRecall(
                    $client,
                    $reservation,
                    Reservation::STATUS_BV_VOICEMAIL,
                    'bv_count',
                    null
                ),
                Note::TYPE_CALL_BACK => $this->handleRecall(
                    $client,
                    $reservation,
                    Reservation::STATUS_CALL_BACK,
                    'injoinable_count',
                    $validated['recall_at'] ?? null
                ),
                Note::TYPE_BLACKLISTED => $this->handleBlacklist($client),
                default => throw new \InvalidArgumentException("Evénement non géré : {$event}"),
            };
        });
    }

    /**
     * Rappel échu sans action : échec automatique. Incrémente le compteur
     * correspondant ; 3e BV -> UNAVAILABLE 21 jours + note automatique de
     * l'employé. Un « à rappeler » n'a pas de seuil : le rappel est retiré,
     * le client reste `RESERVED`. La réservation n'est jamais supprimée.
     *
     * @return bool true si le client a basculé en UNAVAILABLE
     */
    public function handleRecallExpired(Rappel $rappel): bool
    {
        $client = $rappel->client;
        $reservation = $rappel->reservation;

        if (! $client || ! $reservation || $client->status === Client::STATUS_BLACKLISTED) {
            $rappel->delete();

            return false;
        }

        return DB::transaction(function () use ($rappel, $client, $reservation) {
            [$status, $counter] = $reservation->status === Reservation::STATUS_CALL_BACK
                ? [Reservation::STATUS_CALL_BACK, 'injoinable_count']
                : [Reservation::STATUS_BV_VOICEMAIL, 'bv_count'];

            $attempts = $this->bumpCounter($reservation, $counter);
            $limit = $this->attemptsLimit($counter);

            if ($limit !== null && $attempts >= $limit) {
                // 3e tentative : indisponible 21 jours pour tous.
                $this->blockTemporarily($client, $reservation, $status, $counter);

                return true;
            }

            // Sous le seuil : le client reste RESERVED et ré-apparaît dans les
            // listes (rappel retiré => à rappeler manuellement).
            $reservation->update(['status' => $status]);
            $rappel->delete();

            return false;
        });
    }

    private function handleYes(Client $client, ?Reservation $reservation): array
    {
        // CONFIRMED est définitif : plus de compte à rebours de retour.
        $client->update([
            'status' => Client::STATUS_CONFIRMED,
            'returned_at' => null,
        ]);

        if ($reservation) {
            $reservation->update(['status' => Reservation::STATUS_YES]);
            $this->cancelRappel($reservation);
        }

        return $this->result('Client confirmé. Réservation maintenue.', $client, false);
    }

    private function handleNo(Client $client, ?Reservation $reservation, User $actor): array
    {
        $client->update([
            'status' => Client::STATUS_UNAVAILABLE,
            'returned_at' => Carbon::now()->addMonths(self::NON_BLOCK_MONTHS),
        ]);

        if ($reservation) {
            $reservation->update(['status' => Reservation::STATUS_NO]);
            $this->cancelRappel($reservation);
        }

        // Auto-blacklist : vérifiée après chaque NO.
        if ($this->shouldAutoBlacklist($client)) {
            Note::create([
                'client_id' => $client->id,
                'reservation_id' => $reservation?->id,
                'sender_id' => $reservation?->comercial_id ?? $actor->id,
                'type' => Note::TYPE_BLACKLISTED,
                'description' => 'Tous les employés actifs ont répondu NON.',
            ]);

            return $this->handleBlacklist($client);
        }

        return $this->result('Client refusé. Indisponible pour 3 mois.', $client, false);
    }

    /**
     * @param  string  $reservationStatus  Reservation::STATUS_BV_VOICEMAIL|STATUS_CALL_BACK
     * @param  string  $counter  colonne compteur (`bv_count` / `injoinable_count`)
     * @param  ?string  $recallAtInput  date choisie par l'employé (CALL_BACK) ; null = 3 jours
     */
    private function handleRecall(
        Client $client,
        ?Reservation $reservation,
        string $reservationStatus,
        string $counter,
        ?string $recallAtInput = null
    ): array {
        $client->update(['status' => Client::STATUS_RESERVED]);

        if (! $reservation) {
            return $this->result('Rappel configuré.', $client, false);
        }

        $attempts = $this->bumpCounter($reservation, $counter);
        $limit = $this->attemptsLimit($counter);

        if ($limit !== null && $attempts >= $limit) {
            // 3e BV : indisponible 21 jours, rappel annulé, réservation
            // conservée + note automatique de l'employé.
            $this->blockTemporarily($client, $reservation, $reservationStatus, $counter);

            return $this->result('Tentatives épuisées. Client indisponible pour 21 jours.', $client, false);
        }

        // CALL_BACK : datetime choisie par l'employé ; BV (et repli sans
        // saisie) : rappel automatique à 3 jours.
        $isCustom = $recallAtInput !== null && $recallAtInput !== '';
        $reminderDate = $isCustom
            ? Carbon::parse($recallAtInput)
            : Carbon::now()->addDays(self::RECALL_DAYS);

        $this->cancelRappel($reservation);

        Rappel::create([
            'client_id' => $client->id,
            'comercial_id' => $reservation->comercial_id,
            'reservation_id' => $reservation->id,
            'reminder_date' => $reminderDate,
        ]);

        $reservation->update(['status' => $reservationStatus]);

        return $this->result(
            $isCustom ? 'Rappel planifié.' : 'Rappel configuré sous 3 jours.',
            $client,
            false
        );
    }

    public function handleBlacklist(Client $client): array
    {
        $client->update([
            'is_blacklisted' => true,
            'status' => Client::STATUS_BLACKLISTED,
            'returned_at' => null,
        ]);

        $this->cancelRappelsOf($client);

        return $this->result('Client mis en liste noire.', $client, true);
    }

    /**
     * Retire le rappel en cours d'une réservation (un seul à la fois).
     */
    public function cancelRappel(Reservation $reservation): void
    {
        Rappel::where('reservation_id', $reservation->id)->delete();
    }

    /** Retire tous les rappels d'un client (déblocage, liste noire…). */
    public function cancelRappelsOf(Client $client): void
    {
        Rappel::where('client_id', $client->id)->delete();
    }

    /**
     * Tous les employés actifs ont-ils déjà répondu NO pour ce client ?
     */
    private function shouldAutoBlacklist(Client $client): bool
    {
        $activeCommercials = User::where('role', 'COMERCIAL')
            ->where('status', 'ACTIVE')
            ->count();

        if ($activeCommercials === 0) {
            return false;
        }

        $nonCount = Note::where('client_id', $client->id)
            ->where('type', Note::TYPE_NO)
            ->distinct()
            ->count('sender_id');

        return $nonCount >= $activeCommercials;
    }

    /**
     * Seuil d'épuisement d'un compteur, `null` = jamais épuisé.
     * Seul `bv_count` est borné (3) : un « à rappeler » ne se bloque jamais.
     */
    private function attemptsLimit(string $counter): ?int
    {
        return $counter === 'bv_count'
            ? self::BV_ATTEMPTS_LIMIT
            : self::CALL_BACK_ATTEMPTS_LIMIT;
    }

    /**
     * Épuisement des tentatives : client `UNAVAILABLE` 21 jours, rappel
     * annulé, **réservation conservée**, et journalisation automatique d'une
     * note de type `BV` (ou `CALL_BACK`) émise par l'employé qui a changé le
     * statut — l'appel (cas 4) et le rappel expiré (§4, cron) passent par ici.
     */
    private function blockTemporarily(
        Client $client,
        Reservation $reservation,
        string $reservationStatus,
        string $counter
    ): void {
        $client->update([
            'status' => Client::STATUS_UNAVAILABLE,
            'returned_at' => Carbon::now()->addDays(self::TEMP_BLOCK_DAYS),
        ]);

        $reservation->update(['status' => $reservationStatus]);
        $this->cancelRappel($reservation);

        Note::create([
            'client_id' => $client->id,
            'reservation_id' => $reservation->id,
            'sender_id' => $reservation->comercial_id,
            'type' => $counter === 'bv_count' ? Note::TYPE_BV : Note::TYPE_CALL_BACK,
            'description' => self::AUTO_BLOCK_DESCRIPTION,
        ]);
    }

    private function bumpCounter(Reservation $reservation, string $counter): int
    {
        $attempts = (int) $reservation->{$counter} + 1;
        $reservation->update([$counter => $attempts]);

        return $attempts;
    }

    /**
     * @return array{message: string, client_status: string, blacklisted: bool}
     */
    private function result(string $message, Client $client, bool $blacklisted): array
    {
        return [
            'message' => $message,
            'client_status' => $client->fresh()->status,
            'blacklisted' => $blacklisted,
        ];
    }
}
