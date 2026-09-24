<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /** Tous les statuts de réservation (INJOINABLE s'affiche "à RAPPELER"). */
    public const STATUSES = ['EN_ATTENT', 'OUI', 'NON', 'BV', 'INJOINABLE'];

    /** Réservations encore "tenues" par le commercial (le client est RESERVED/SUCCESS). */
    public const ACTIVE_STATUSES = ['EN_ATTENT', 'OUI', 'BV', 'INJOINABLE'];

    protected $fillable = [
        'client_id',
        'comercial_id',
        'reservation_group_id',
        'status',
        'bv_count',
        'injoinable_count',
        'rappel_after',
        'rappel_type',
        'recall_at',
    ];

    protected function casts(): array
    {
        return [
            'bv_count' => 'integer',
            'injoinable_count' => 'integer',
            'recall_at' => 'datetime',
        ];
    }

    /**
     * Réservations actives : le couple (statut de réservation, statut client)
     * montre que le client est toujours tenu par le commercial.
     */
    public function scopeActive($query)
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereHas('client', fn ($q) => $q->whereIn('status', ['RESERVED', 'SUCCESS']));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function comercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comercial_id');
    }
}