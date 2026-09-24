<?php

namespace App\Models;

use App\Models\Concerns\LimitsNoteWords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallOutcome extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    use LimitsNoteWords;

    /** Statuts d'appel autorisés (alignés sur reservation.status). */
    public const OUTCOMES = ['OUI', 'NON', 'BV', 'INJOINABLE', 'BLACKLIST', 'UNBLACKLIST'];

    protected $fillable = [
        'client_id',
        'comercial_id',
        'outcome',
        'note',
        'recall_amount',
        'recall_unit',
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

    public function comercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comercial_id');
    }

    protected function noteWordField(): string
    {
        return 'note';
    }
}
