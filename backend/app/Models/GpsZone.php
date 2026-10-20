<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Zone de géorepérage (cercle) utilisée au contrôle de connexion GPS —
 * voir `App\Services\GeofenceService` et la page admin « GPS ».
 *
 * Gestion CRUD réservée aux rôles ADMIN / SUPER_ADMIN
 * (`GpsZoneController`) ; les commerciaux n'y ont aucun accès.
 */
class GpsZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'radius_m',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
