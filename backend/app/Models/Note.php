<?php

namespace App\Models;

use App\Models\Concerns\LimitsNoteWords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'interactions d'un client — classe Note du modèle :
 *
 *   id, client_id, reservation_id?, sender_id (utilisateur ou 'SYSTEM'),
 *   type, description, created_at
 *
 * `type` reprend l'énumération du modèle et remplace l'ancienne table
 * `call_outcomes` (fusionnée ici) :
 *
 *   RESERVED               réservation d'un lot
 *   YES / NO               issue d'appel
 *   BV                     boîte vocale
 *   CALL_BACK              à rappeler (issue injoignable)
 *   BLACKLISTED            mise en liste noire
 *   RETURNED_TO_AVAILABLE  déblocage admin ou réactivation automatique (cron)
 *   NOTE                   commentaire libre de l'employé (8 mots max)
 *
 * `sender_id` peut valoir `SYSTEM` : les événements produits par les crons
 * n'ont pas d'utilisateur. Contrairement à l'ancienne clé étrangère
 * `comercial_id`, la suppression d'un compte ne supprime donc pas
 * l'historique.
 */
class Note extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    use LimitsNoteWords;

    /** Émetteur des événements automatiques (cron `clients:reactivate`…). */
    public const SENDER_SYSTEM = 'SYSTEM';

    // ------------------------------------------------------------------
    // Types (docs/models.puml)
    // ------------------------------------------------------------------

    public const TYPE_RESERVED = 'RESERVED';

    public const TYPE_YES = 'YES';

    public const TYPE_NO = 'NO';

    public const TYPE_BV = 'BV';

    public const TYPE_CALL_BACK = 'CALL_BACK';

    public const TYPE_BLACKLISTED = 'BLACKLISTED';

    public const TYPE_RETURNED_TO_AVAILABLE = 'RETURNED_TO_AVAILABLE';

    public const TYPE_NOTE = 'NOTE';

    /** Énumération du modèle. */
    public const TYPES = [
        self::TYPE_RESERVED,
        self::TYPE_YES,
        self::TYPE_NO,
        self::TYPE_BV,
        self::TYPE_CALL_BACK,
        self::TYPE_BLACKLISTED,
        self::TYPE_RETURNED_TO_AVAILABLE,
        self::TYPE_NOTE,
    ];

    /**
     * Issues d'appel : seules ces notes rendent un prospect « traité »
     * (KPI overview) et déclenchent l'auto-blacklist. `RESERVED` en est
     * exclu : elle est créée à la réservation, avant tout appel.
     */
    public const CALL_TYPES = [
        self::TYPE_YES,
        self::TYPE_NO,
        self::TYPE_BV,
        self::TYPE_CALL_BACK,
    ];

    protected $fillable = [
        'client_id',
        'reservation_id',
        'sender_id',
        'type',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Réservation qui a produit l'événement (note `RESERVED` créée par la
     * réservation d'un lot). Nullable : les commentaires et la plupart des
     * événements ne la connaissent pas.
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Émetteur : un utilisateur, ou null pour `SYSTEM` (les événements du
     * cron n'ont pas de compte).
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Issues d'appel uniquement (KPI « traité », auto-blacklist). */
    public function scopeCalls(Builder $query): Builder
    {
        return $query->whereIn('type', self::CALL_TYPES);
    }

    /** Commentaires libres (les événements n'en sont pas). */
    public function scopeComments(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_NOTE);
    }

    protected function noteWordField(): string
    {
        return 'description';
    }

    /**
     * La limite de 8 mots porte sur la **saisie humaine** : les descriptions
     * générées (`sender_id = SYSTEM`, ex. « Réservé par … le … »)
     * ne sont pas de la saisie et ne sont donc pas comptées.
     */
    protected function noteWordsAreLimited(): bool
    {
        return $this->sender_id !== self::SENDER_SYSTEM;
    }
}
