<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rappel planifié (classe Rappel du modèle) :
 *
 *   rappels.id, client_id, comercial_id, reservation_id, reminder_date,
 *   done_at?, done_note?, created_at
 *
 * `done_at` reste NULL tant que le rappel est en attente : les listes
 * (« Rappels » / « Auto-rappels ») n'affichent que `done_at IS NULL`.
 * `done_note` garde la note saisie lors de la prise en compte, la même note
 * étant écrite dans `notes` pour apparaître dans l'historique du client.
 *
 * Remplace les anciennes colonnes de `reservations` (`recall_at`,
 * `rappel_after`, `rappel_type`) : `rappels` est la source de vérité unique.
 *
 *  - Rappel BV_VOICEMAIL : automatique, toujours 3 jours (aucune saisie).
 *  - Rappel CALL_BACK    : date/heure choisie par l'employé.
 *
 * Le délai d'affichage (« Rappel dans X ») n'est plus stocké : `delay()`
 * le recalcule depuis `reminder_date`, dans l'unité la plus lisible.
 */
class Rappel extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'rappels';

    protected $fillable = [
        'client_id',
        'comercial_id',
        'reservation_id',
        'reminder_date',
        'done_at',
        'done_note',
    ];

    protected function casts(): array
    {
        return [
            'reminder_date' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function comercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comercial_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** Le rappel est-il échu ? */
    public function isDue(): bool
    {
        return $this->reminder_date->lte(Carbon::now());
    }

    /** Le rappel a-t-il été marqué comme terminé ? */
    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Marque le rappel comme terminé (note facultative).
     *
     * Le rappel quitte alors les listes : `scopePending()` n'expose que
     * `done_at IS NULL`.
     */
    public function markAsDone(?string $note): void
    {
        $this->forceFill(['done_at' => now(), 'done_note' => $note])->save();
    }

    /** Rappels en attente — seuls ceux-ci sont listés. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * Délai d'affichage du rappel, dans l'unité la plus lisible.
     *
     * Les minutes sont arrondies (et non tronquées) pour que now + 3 jours
     * affiche bien « 3 j » même si quelques millisecondes se sont écoulées
     * entre le calcul de `reminder_date` et l'appel.
     *
     * @return array{0: int, 1: string} [montant, unité MINUTE|HEURE|JOUR]
     */
    public function delay(): array
    {
        $minutes = (int) round(max(
            0,
            $this->reminder_date->getTimestamp() - Carbon::now()->getTimestamp()
        ) / 60);

        if ($minutes < 60) {
            return [max(1, $minutes), 'MINUTE'];
        }

        if ($minutes < 1440) {
            return [(int) floor($minutes / 60), 'HEURE'];
        }

        return [(int) floor($minutes / 1440), 'JOUR'];
    }

    /** Rapport `[montant, unité]` prêt à être exposé dans un payload. */
    public function delayPayload(): array
    {
        [$amount, $unit] = $this->delay();

        return [
            'recall_after' => $amount,
            'recall_unit' => $unit,
        ];
    }
}
