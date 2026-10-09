<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enterprise extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'tax_number',
        'phone',
        'address',
        'logo',
        'status',
        // Compte RingCentral propre à l'entreprise (optionnel)
        'ringcentral_client_id',
        'ringcentral_client_secret',
        'ringcentral_token',
        'source',
    ];

    /** Identifiants RingCentral stockés directement sur l'entreprise. */
    public function getRingCentralCredentials(): array
    {
        return [
            'client_id' => $this->ringcentral_client_id,
            'client_secret' => $this->ringcentral_client_secret,
            'token' => $this->ringcentral_token,
            'server_url' => config('services.ringcentral.server_url', 'https://platform.ringcentral.com'),
        ];
    }

    /** L'entreprise a-t-elle les trois identifiants RingCentral enregistrés ? */
    public function hasOwnRingCentralAccount(): bool
    {
        return filled($this->ringcentral_client_id)
            && filled($this->ringcentral_client_secret)
            && filled($this->ringcentral_token);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'enterprise_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
