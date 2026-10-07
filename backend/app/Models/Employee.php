<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'enterprise_id',
        'first_name',
        'last_name',
        'phone',
        'image_dp',
        'additional_info',
        'ringcentral_device_id',
        'ringcentral_from_number',
        'ringcentral_extension_id',
        'ringcentral_extension_number',
        'ringcentral_phone_numbers',
        'ringcentral_synced_at',
    ];

    /**
     * Correspondance employé ↔ poste RingCentral établie par la synchro
     * (`RingCentralSyncService::syncEmployees()`) : tous les numéros
     * assignés au poste, sous forme de liste.
     */
    protected $casts = [
        'ringcentral_phone_numbers' => 'array',
        'ringcentral_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
