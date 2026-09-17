<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'enterprise_id',
        'categories',
        'categories_id',
        'rbq_data',
        'status',
        'assigned_comercial_id',
        'is_blacklisted',
        // Licence
        'licence_number',
        'licence_propre',
        'intervenant_name',
        'licence_status',
        // Identification
        'neq',
        'full_address',
        'municipality',
        'administrative_region',
        'phone',
        'email',
        // Répondants
        'respondent_count',
        'respondents',
        // Catégories
        'sub_category_count',
        'authorized_categories',
        // Cautionnement
        'surety_company',
        'surety_amount',
        // Dates
        'licence_start_date',
        'licence_end_date',
        // Représentant
        'representative_name',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'categories_id' => 'array',
            'rbq_data' => 'array',
            'is_blacklisted' => 'boolean',
            'licence_propre' => 'boolean',
            'respondents' => 'array',
            'authorized_categories' => 'array',
            'surety_amount' => 'decimal:2',
            'licence_start_date' => 'date',
            'licence_end_date' => 'date',
        ];
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function assignedComercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_comercial_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
