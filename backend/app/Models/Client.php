<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'categories',
        'categories_id',
        'rbq_data',
        'status',
        'is_blacklisted',
        'blocked_until',
        'licence_number',
        'licence_propre',
        'intervenant_name',
        'licence_status',
        'neq',
        'full_address',
        'municipality',
        'administrative_region',
        'phone',
        'email',
        'respondent_count',
        'respondents',
        'sub_category_count',
        'authorized_categories',
        'surety_company',
        'surety_amount',
        'licence_start_date',
        'licence_end_date',
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
            'blocked_until' => 'datetime',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function activeReservation()
    {
        return $this->hasOne(Reservation::class)->latest('created_at');
    }

    public function callOutcomes(): HasMany
    {
        return $this->hasMany(CallOutcome::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function getEnterpriseNameAttribute(): ?string
    {
        return $this->rbq_data['entreprise_name'] ?? null;
    }

    public function getNameAttribute(): ?string
    {
        return $this->rbq_data['name'] ?? null;
    }
}
