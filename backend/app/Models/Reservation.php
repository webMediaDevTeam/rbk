<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Réservation d'un lot de prospects (classe Reservation du modèle) :
 *
 *   id, client_id, comercial_id, reservation_group_id, status,
 *   bv_count, injoinable_count, created_at
 *
 * Les rappels ont leur propre table (`rappels`) : `recall_at` /
 * `rappel_after` / `rappel_type` ont disparu de la réservation.
 */
class Reservation extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    // ------------------------------------------------------------------
    // Statuts (docs/models.puml)
    // ------------------------------------------------------------------

    /** Réservé, pas encore appelé. */
    public const STATUS_PENDING = 'PENDING';

    /** Le prospect a refusé. */
    public const STATUS_NO = 'NO';

    /** Le prospect est intéressé (client -> CONFIRMED). */
    public const STATUS_YES = 'YES';

    /** Boîte vocale : rappel automatique à 3 jours. */
    public const STATUS_BV_VOICEMAIL = 'BV_VOICEMAIL';

    /** À rappeler : rappel à la date choisie par l'employé. */
    public const STATUS_CALL_BACK = 'CALL_BACK';

    /**
     * Valeur réservée du modèle (affaire réalisée) — **non émise** par le
     * workflow actuel, conservée pour l'évolution du cycle de vie.
     */
    public const STATUS_REALIZED = 'REALIZED';

    /** Tous les statuts (INJOINABLE s'affichait « à RAPPELER »). */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_NO,
        self::STATUS_YES,
        self::STATUS_BV_VOICEMAIL,
        self::STATUS_CALL_BACK,
        self::STATUS_REALIZED,
    ];

    /** Réservations encore « tenues » par l'employé (client RESERVED/CONFIRMED). */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_YES,
        self::STATUS_BV_VOICEMAIL,
        self::STATUS_CALL_BACK,
    ];

    /** Statuts comptés comme « traités » dans les listes (tout sauf PENDING). */
    public const PROCESSED_STATUSES = [
        self::STATUS_NO,
        self::STATUS_YES,
        self::STATUS_BV_VOICEMAIL,
        self::STATUS_CALL_BACK,
    ];

    protected $fillable = [
        'client_id',
        'comercial_id',
        'reservation_group_id',
        'status',
        'bv_count',
        'injoinable_count',
    ];

    protected function casts(): array
    {
        return [
            'bv_count' => 'integer',
            'injoinable_count' => 'integer',
        ];
    }

    /**
     * Réservations actives : le couple (statut de réservation, statut client)
     * montre que le client est toujours tenu par l'employé.
     */
    public function scopeActive($query)
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereHas('client', fn ($q) => $q->whereIn('status', [
                Client::STATUS_RESERVED,
                Client::STATUS_CONFIRMED,
            ]));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function comercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comercial_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReservationGroup::class, 'reservation_group_id');
    }

    /** Rappel en cours de la réservation (aucun si le rappel est échu/annulé). */
    public function rappel(): HasOne
    {
        return $this->hasOne(Rappel::class)->latestOfMany('created_at', 'id');
    }
}
