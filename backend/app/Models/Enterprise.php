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
        // Compte RingCentral propre à l'entreprise (optionnel, repli `.env`)
        'ringcentral_client_id',
        'ringcentral_client_secret',
        'ringcentral_token',
        'source',
    ];

    /**
     * Identifiants RingCentral **de l'entreprise**, avec repli sur `.env`
     * (`config('services.ringcentral.*')`) champ par champ : une entreprise
     * sans identifiants propres → compte global inchangé ; une entreprise
     * partiellement remplie → le champ manquant vient aussi de `.env`.
     *
     * @return array{client_id: ?string, client_secret: ?string, token: ?string, server_url: string}
     */
    public function getRingCentralCredentials(): array
    {
        return [
            'client_id' => $this->ringcentral_client_id ?: config('services.ringcentral.client_id'),
            'client_secret' => $this->ringcentral_client_secret ?: config('services.ringcentral.client_secret'),
            'token' => $this->ringcentral_token ?: config('services.ringcentral.jwt'),
            'server_url' => config('services.ringcentral.server_url', 'https://platform.ringcentral.com'),
        ];
    }

    /** L'entreprise a-t-elle **son propre** compte (indépendant de `.env`) ? */
    public function hasOwnRingCentralAccount(): bool
    {
        return filled($this->ringcentral_client_id) && filled($this->ringcentral_client_secret);
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
