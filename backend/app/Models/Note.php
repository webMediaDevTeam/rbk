<?php

namespace App\Models;

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
 *   REPETE                 rattachement manuel de la fiche à son doublon
 *                          d'origine (« marquer comme répété », depuis la
 *                          fiche du parent) ; pose aussi `status = REPETE`
 *   RETURNED_TO_AVAILABLE  déblocage admin ou réactivation automatique (cron)
 *   NOTE                   commentaire libre de l'employé
 *   MISE_A_JOUR            diff automatique d'édition de fiche (toute la
 *                          description est générée, champ par champ — voir
 *                          `ClientUpdateNote`)
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

    public const TYPE_DOUBLE = 'DOUBLE';

    public const TYPE_INFO = 'INFO';

    /**
     * Issue « Autre » : comme `DOUBLE` / `INFO`, l'appel a eu lieu et le
     * prospect reste **tenu** par l'employé (régime `RESERVED`) — seule la
     * raison diffère, portée par la note libre de l'employé.
     */
    public const TYPE_AUTRE = 'AUTRE';

    public const TYPE_BLACKLISTED = 'BLACKLISTED';

    /** Fiche rattachée à son doublon d'origine (`parent_client_id` posé). */
    public const TYPE_REPETE = 'REPETE';

    public const TYPE_RETURNED_TO_AVAILABLE = 'RETURNED_TO_AVAILABLE';

    public const TYPE_NOTE = 'NOTE';

    /** Diff d'édition de fiche généré à chaque mise à jour humaine. */
    public const TYPE_MISE_A_JOUR = 'MISE_A_JOUR';

    /** Énumération du modèle. */
    public const TYPES = [
        self::TYPE_RESERVED,
        self::TYPE_YES,
        self::TYPE_NO,
        self::TYPE_BV,
        self::TYPE_CALL_BACK,
        self::TYPE_DOUBLE,
        self::TYPE_INFO,
        self::TYPE_AUTRE,
        self::TYPE_BLACKLISTED,
        self::TYPE_REPETE,
        self::TYPE_RETURNED_TO_AVAILABLE,
        self::TYPE_NOTE,
        self::TYPE_MISE_A_JOUR,
    ];

    /**
     * Issues d'appel : seules ces notes rendent un prospect « traité »
     * (KPI overview) et déclenchent l'auto-blacklist. `RESERVED` en est
     * exclu : elle est créée à la réservation, avant tout appel.
     *
     * `DOUBLE` / `INFO` sont des issues comme les autres : l'appel a eu
     * lieu, seul le sort du prospect diffère (il reste tenu par l'employé,
     * régime `RESERVED`).
     */
    public const CALL_TYPES = [
        self::TYPE_YES,
        self::TYPE_NO,
        self::TYPE_BV,
        self::TYPE_CALL_BACK,
        self::TYPE_DOUBLE,
        self::TYPE_INFO,
        self::TYPE_AUTRE,
    ];

    protected $fillable = [
        'client_id',
        'call_log_id',
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

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
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
}
