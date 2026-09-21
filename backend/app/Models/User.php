<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'phone',
        'role',
        'status',
        'avatar',
        'email_verified_at',
        'verification_token',
        'verification_sent_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'email_verified_at' => 'datetime',
            'verification_sent_at' => 'datetime',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function assignedClients()
    {
        return Client::whereHas('reservations', function ($q) {
            $q->where('comercial_id', $this->id)
              ->where('expires_at', '>', now());
        });
    }

    public function createdReservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'comercial_id');
    }

    public function createdNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'comercial_id');
    }
}
