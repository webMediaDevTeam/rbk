<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enregistrement audio d'un appel (métadonnées seulement — l'audio reste
 * chez RingCentral et passe par le proxy
 * `GET /call-logs/recordings/{ringcentral_recording_id}/content`).
 *
 *   call_recordings.id, call_log_id, ringcentral_recording_id (unique),
 *   type, duration (s), file_name, content_uri, synced_at, timestamps
 *
 * L'id RingCentral est la clé de dé-doublonnage : la même ligne est
 * réécrite par la synchro (`updateOrCreate`) et par le démarrage « à chaud »
 * de l'enregistrement au moment de l'appel.
 */
class CallRecording extends Model
{
    use HasUuids;

    protected $fillable = [
        'call_log_id',
        'ringcentral_recording_id',
        'type',
        'duration',
        'file_name',
        'content_uri',
        'synced_at',
    ];

    protected $casts = [
        'duration'  => 'integer',
        'synced_at' => 'datetime',
    ];

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    /** Format de l'onglet « Appels » (clés RingCentral). */
    public function toApiArray(): array
    {
        return [
            'id'          => $this->ringcentral_recording_id,
            'type'        => $this->type,
            'duration'    => $this->duration,
            'file_name'   => $this->file_name,
            'content_uri' => $this->content_uri,
        ];
    }
}
