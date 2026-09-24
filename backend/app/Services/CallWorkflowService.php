<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CallOutcome;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Workflow d'appel (voir docs/RULES.md) :
 *
 *  - OUI         -> client SUCCESS, réservation OUI, maintenue jusqu'à clôture admin.
 *  - NON         -> client UNAVAILABLE_TEMP, returned_at = now + 3 mois, réservation NON,
 *                   + vérification auto-blacklist (tous les employés actifs ont dit NON).
 *  - BV          -> le client reste RESERVED, bv_count++, rappel automatique à 3 jours ;
 *                   si bv_count >= 2 -> UNAVAILABLE_TEMP 21 jours.
 *  - INJOINABLE  -> idem avec injoinable_count (affiché "à RAPPELER"), mais le rappel
 *                   est planifié à la date/heure choisie par l'employé (recall_at).
 *  - BLACKLIST   -> client BLACKLISTED.
 *
 * Plus aucune expiration de réservation (expires_at supprimée) et aucune
 * libération manuelle : seul l'unblock admin vide les réservations.
 */
class CallWorkflowService
{
    /** Rappel automatique par défaut (BV : 3 jours ; repli INJOINABLE sans saisie). */
    public const RECALL_DAYS = 3;

    /** Indisponibilité après NON. */
    public const NON_BLOCK_MONTHS = 3;

    /** Indisponibilité après 2 BV / 2 Injoignables. */
    public const TEMP_BLOCK_DAYS = 21;

    /** Seuil de tentatives avant UNAVAILABLE_TEMP. */
    public const ATTEMPTS_LIMIT = 2;

    /**
     * Applique un outcome sur un client et retourne le message métier.
     *
     * @param array{note?: ?string, recall_at?: ?string} $validated recall_at : date/heure
     *        du rappel pour INJOINABLE (ignoré pour les autres issues).
     * @return array{message: string, client_status: string, blacklisted: bool}
     */
    public function apply(Client $client, ?Reservation $reservation, string $outcome, array $validated, User $actor): array
    {
        return DB::transaction(function () use ($client, $reservation, $outcome, $validated, $actor) {
            $note = trim((string) ($validated['note'] ?? ''));

            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $actor->id,
                'outcome' => $outcome,
                'note' => $note === '' ? null : $note,
            ]);

            return match ($outcome) {
                'OUI' => $this->handleOui($client, $reservation),
                'NON' => $this->handleNon($client, $reservation, $actor),
                'BV' => $this->handleRecall($client, $reservation, 'BV', 'bv_count'),
                'INJOINABLE' => $this->handleRecall(
                    $client,
                    $reservation,
                    'INJOINABLE',
                    'injoinable_count',
                    $validated['recall_at'] ?? null
                ),
                'BLACKLIST' => $this->handleBlacklist($client),
                default => throw new \InvalidArgumentException("Outcome non géré : {$outcome}"),
            };
        });
    }

    /**
     * Rappel expiré sans action : échec automatique.
     * Incrémente le compteur correspondant ; si >= 2 -> UNAVAILABLE_TEMP 21 jours.
     * La réservation n'est jamais supprimée.
     *
     * @return bool true si le client a basculé en UNAVAILABLE_TEMP
     */
    public function handleRecallExpired(Reservation $reservation): bool
    {
        $client = $reservation->client;

        if (! $client || $client->status === 'BLACKLISTED') {
            $reservation->update(['recall_at' => null]);

            return false;
        }

        return DB::transaction(function () use ($reservation, $client) {
            [$status, $counter] = $reservation->status === 'INJOINABLE'
                ? ['INJOINABLE', 'injoinable_count']
                : ['BV', 'bv_count'];

            $attempts = $this->bumpCounter($reservation, $counter);

            if ($attempts >= self::ATTEMPTS_LIMIT) {
                $client->update([
                    'status' => 'UNAVAILABLE_TEMP',
                    'returned_at' => Carbon::now()->addDays(self::TEMP_BLOCK_DAYS),
                ]);
                $reservation->update(['status' => $status, 'recall_at' => null]);

                return true;
            }

            // Sous le seuil : le client reste RESERVED et ré-apparaît dans les listes
            // (recall_at vidé => à rappeler manuellement par l'employé).
            $reservation->update(['status' => $status, 'recall_at' => null]);

            return false;
        });
    }

    private function handleOui(Client $client, ?Reservation $reservation): array
    {
        $client->update(['status' => 'SUCCESS']);

        if ($reservation) {
            $reservation->update(['status' => 'OUI', 'recall_at' => null]);
        }

        return $this->result('Client confirmé. Réservation maintenue.', $client, false);
    }

    private function handleNon(Client $client, ?Reservation $reservation, User $actor): array
    {
        $client->update([
            'status' => 'UNAVAILABLE_TEMP',
            'returned_at' => Carbon::now()->addMonths(self::NON_BLOCK_MONTHS),
        ]);

        if ($reservation) {
            $reservation->update(['status' => 'NON', 'recall_at' => null]);
        }

        // Auto-blacklist : vérifié après chaque NON.
        if ($this->shouldAutoBlacklist($client)) {
            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $reservation?->comercial_id ?? $actor->id,
                'outcome' => 'BLACKLIST',
                'note' => 'Tous les employés actifs ont répondu NON.',
            ]);

            return $this->handleBlacklist($client);
        }

        return $this->result('Client refusé. Indisponible pour 3 mois.', $client, false);
    }

    private function handleRecall(
        Client $client,
        ?Reservation $reservation,
        string $status,
        string $counter,
        ?string $recallAtInput = null
    ): array {
        $client->update(['status' => 'RESERVED']);

        if (! $reservation) {
            return $this->result('Rappel configuré.', $client, false);
        }

        $attempts = $this->bumpCounter($reservation, $counter);

        if ($attempts >= self::ATTEMPTS_LIMIT) {
            // 2e tentative : indisponible 21 jours pour tous.
            $client->update([
                'status' => 'UNAVAILABLE_TEMP',
                'returned_at' => Carbon::now()->addDays(self::TEMP_BLOCK_DAYS),
            ]);
            $reservation->update(['status' => $status, 'recall_at' => null]);

            return $this->result('Tentatives épuisées. Client indisponible pour 21 jours.', $client, false);
        }

        // INJOINABLE : datetime choisie par l'employé (UDAPTE.md) ;
        // BV (et repli INJOINABLE sans saisie) : rappel automatique à 3 jours.
        $isCustom = $recallAtInput !== null && $recallAtInput !== '';
        $recallAt = $isCustom
            ? Carbon::parse($recallAtInput)
            : Carbon::now()->addDays(self::RECALL_DAYS);

        [$amount, $unit] = $this->recallDelay($recallAt);

        $reservation->update([
            'status' => $status,
            'recall_at' => $recallAt,
            'rappel_after' => $amount,
            'rappel_type' => $unit,
        ]);

        return $this->result(
            $isCustom ? 'Rappel planifié.' : 'Rappel configuré sous 3 jours.',
            $client,
            false
        );
    }

    /**
     * Délai d'affichage du rappel, exprimé dans l'unité la plus lisible.
     *
     * Les minutes sont arrondies (et non tronquées) pour que now + 3 jours
     * affiche bien « 3 j » même si quelques millisecondes se sont écoulées
     * entre le calcul de recall_at et l'appel.
     *
     * @return array{0: int, 1: string} [montant, unité] avec MINUTE / HEURE / JOUR
     */
    private function recallDelay(Carbon $recallAt): array
    {
        $minutes = (int) round(max(0, $recallAt->getTimestamp() - Carbon::now()->getTimestamp()) / 60);

        if ($minutes < 60) {
            return [max(1, $minutes), 'MINUTE'];
        }

        if ($minutes < 1440) {
            return [(int) floor($minutes / 60), 'HEURE'];
        }

        return [(int) floor($minutes / 1440), 'JOUR'];
    }

    public function handleBlacklist(Client $client, ?string $note = null): array
    {
        $client->update([
            'is_blacklisted' => true,
            'status' => 'BLACKLISTED',
            'returned_at' => null,
        ]);

        return $this->result('Client mis en liste noire.', $client, true);
    }

    /**
     * Tous les employés actifs ont-ils déjà répondu NON pour ce client ?
     */
    private function shouldAutoBlacklist(Client $client): bool
    {
        $activeCommercials = User::where('role', 'COMERCIAL')
            ->where('status', 'ACTIVE')
            ->count();

        if ($activeCommercials === 0) {
            return false;
        }

        $nonCount = CallOutcome::where('client_id', $client->id)
            ->where('outcome', 'NON')
            ->distinct()
            ->count('comercial_id');

        return $nonCount >= $activeCommercials;
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
