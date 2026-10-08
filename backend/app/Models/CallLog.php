<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Appel stocké (docs/TODOS.md « Sync Call Logs ») — source de vérité de
 * l'onglet « Appels » d'une fiche employé.
 *
 *   call_logs.id, employee_id?, ringcentral_call_id?, ringcentral_session_id?,
 *   ringcentral_extension_id?, ringcentral_party_id?, direction, type,
 *   from_number, from_name, to_number, to_name, started_at, ended_at,
 *   duration (s), result, raw (json), synced_at, timestamps
 *
 * Deux entrées possibles pour la même ligne :
 *
 *   - **synchro** : l'id du call log RingCentral (`ringcentral_call_id`,
 *     unique) dé-doublonne ;
 *   - **appel lancé depuis l'application** : seule la session est connue au
 *     moment de l'écriture (`ringcentral_session_id`), la synchro suivante
 *     raccroche le call log à la ligne existante au lieu d'en créer une
 *     seconde.
 *
 * Les champs (`duration`, `result`, `recording`) arrivent par la synchro :
 * une ligne créée « à chaud » reste donc partielle tant que l'appel n'est
 * pas terminé.
 */
class CallLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'employee_id',
        'client_id',
        'ringcentral_call_id',
        'ringcentral_session_id',
        'ringcentral_extension_id',
        'ringcentral_party_id',
        'direction',
        'type',
        'from_number',
        'from_name',
        'to_number',
        'to_name',
        'started_at',
        'ended_at',
        'duration',
        'result',
        'raw',
        'synced_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration' => 'integer',
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    /**
     * Ligne au format attendu par l'onglet « Appels » : **les mêmes clés
     * que l'enregistrement RingCentral** (`startTime`, `from.phoneNumber`,
     * `result`, `recording.id`…), le front ne distingue donc pas le local
     * du distant.
     */
    public function toApiArray(): array
    {
        $recordings = $this->recordings;

        return [
            'id' => $this->ringcentral_call_id ?? $this->id,
            'local_id' => $this->id,
            'session_id' => $this->ringcentral_session_id,
            'direction' => $this->direction,
            'type' => $this->type,
            'startTime' => $this->started_at?->toIso8601String(),
            'endTime' => $this->ended_at?->toIso8601String(),
            'duration' => $this->duration,
            'result' => $this->result,
            'from' => [
                'phoneNumber' => $this->from_number,
                'name' => $this->from_name,
            ],
            'to' => [
                'phoneNumber' => $this->to_number,
                'name' => $this->to_name,
            ],
            // Lecture de l'audio : la carte n'affiche qu'un lecteur, on lui
            // donne le premier enregistrement ; `recordings` reste disponible.
            'recording' => $recordings->first()?->toApiArray(),
            'recordings' => $recordings->map->toApiArray()->values()->all(),
        ];
    }
}
