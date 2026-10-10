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
        // `ringcentral_auth` volontairement hors fillable : écrit uniquement
        // par `getRingCentralCredentials()` via `forceFill` (jamais reçu
        // d'un formulaire API).
        'source',
    ];

    protected function casts(): array
    {
        return [
            // Jeton OAuth obtenu (JWT → access_token) et persisté en base :
            // tableau brut du SDK (`access_token`, `refresh_token`,
            // `expire_time`…), propre à cette entreprise.
            'ringcentral_auth' => 'array',
        ];
    }

    /**
     * Identifiants RingCentral stockés directement sur l'entreprise.
     *
     * La paire `auth` / `auth_persister` donne au `RingCentralService` le
     * jeton **déjà obtenu** par cette entreprise (evite un nouvel échange
     * JWT à chaque requête) et le moyen de le **réécrire** dès qu'il est
     * renouvelé — une entreprise = ses propres jetons, en base.
     */
    public function getRingCentralCredentials(): array
    {
        return [
            'client_id' => $this->ringcentral_client_id,
            'client_secret' => $this->ringcentral_client_secret,
            'token' => $this->ringcentral_token,
            'server_url' => config('services.ringcentral.server_url', 'https://platform.ringcentral.com'),
            'auth' => $this->ringcentral_auth,
            'auth_persister' => function (array $auth): void {
                $this->forceFill(['ringcentral_auth' => $auth])->saveQuietly();
            },
        ];
    }

    /** L'entreprise a-t-elle les trois identifiants RingCentral enregistrés ? */
    public function hasOwnRingCentralAccount(): bool
    {
        return filled($this->ringcentral_client_id)
            && filled($this->ringcentral_client_secret)
            && filled($this->ringcentral_token);
    }

    /**
     * Correction des identifiants RingCentral (onglet « RingCentral » de la
     * fiche entreprise) — sémantique identique à `PUT /entreprises/{id}` :
     * clé absente = valeur conservée, `''` / `null` = valeur retirée,
     * sinon la valeur est rognée (trim) et enregistrée. Écrit ciblé via
     * `forceFill` : seuls les trois champs concernés bougent, jamais
     * `ringcentral_auth` (jeton OAuth auto-géré).
     */
    public function updateRingCentralCredentials(array $credentials): void
    {
        $updates = [];

        foreach (['ringcentral_client_id', 'ringcentral_client_secret', 'ringcentral_token'] as $column) {
            if (! array_key_exists($column, $credentials)) {
                continue; // clé absente → valeur enregistrée conservée
            }

            $value = trim((string) ($credentials[$column] ?? ''));
            $updates[$column] = $value === '' ? null : $value;
        }

        if ($updates !== []) {
            $this->forceFill($updates)->save();
        }
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
