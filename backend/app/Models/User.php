<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Larastan ne déduit pas les casts depuis la méthode `casts()` : sans cette
 * ligne, `gps_bypass_expires_at` reste un `string` et les appels `isFuture()` /
 * `format()` / `toIso8601String()` tombent en faux positif (même famille que
 * ceux déjà figés dans `phpstan-baseline.neon`).
 *
 * @property Carbon|null $gps_bypass_expires_at
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const UPDATED_AT = null;

    /**
     * Durée de la dérogation « Connexion hors zones » : le switch pose une
     * échéance à `now + 24 h` (`gps_bypass_expires_at`), au-delà la dérogation
     * ne passe plus côté serveur et le cron la remet à `false`.
     */
    public const GPS_BYPASS_TTL_HOURS = 24;

    protected $fillable = [
        'email',
        // Nom d'utilisateur (identifiant court, unique) : saisi ou déduit
        // de l'e-mail dans les modales employé, stocké en minuscules.
        'username',
        'password_hash',
        'first_name',
        'last_name',
        'phone',
        'role',
        'status',
        // « Privilège de libération » : posé par l'admin (modale Commercial),
        // lu par le middleware `CheckPermission` — un COMERCIAL sans le
        // drapeau ne peut pas libérer sa liste (la mise en liste noire
        // est ouverte à tous depuis la refonte de la fiche client).
        'has_permission',
        // Dérogation GPS : `true` = ce compte peut se connecter en dehors de
        // toute zone (posée par un gestionnaire, page Employés). Les rôles
        // non contraints (ADMIN / SUPER_ADMIN) n'en ont pas besoin — ils ne
        // sont jamais bloqués (`GeofenceService`). Limitée à 24 h : voir
        // `gps_bypass_expires_at` et `isGpsBypassActive()`.
        'gps_bypass',
        // Échéance de la dérogation GPS, posée par `setGpsBypass()` ; `null`
        // = pas d'échéance connue (désactivée, ou dérogation antérieure à la
        // limitation dans le temps).
        'gps_bypass_expires_at',
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
            'has_permission' => 'boolean',
            'gps_bypass' => 'boolean',
            'gps_bypass_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'verification_sent_at' => 'datetime',
        ];
    }

    /**
     * Dérogation GPS **effective** : posée et pas encore expirée. Un seul
     * point de lecture pour le géorepérage (`GeofenceService`) comme pour
     * l'affichage (page Employés) : le switch montre exactement ce que le
     * serveur acceptera à la prochaine connexion.
     *
     * Une échéance `null` vaut « sans date de fin » : les dérogations accordées
     * avant la limitation dans le temps (`gps_bypass = true`, échéance jamais
     * posée) restent valides — toute **nouvelle** activation passe par
     * `setGpsBypass()` et porte donc 24 h.
     */
    public function isGpsBypassActive(): bool
    {
        if (! $this->gps_bypass) {
            return false;
        }

        return $this->gps_bypass_expires_at === null
            || $this->gps_bypass_expires_at->isFuture();
    }

    /**
     * Pose / retire la dérogation hors zones **avec son échéance de 24 h**.
     * Unique portail d'écriture du drapeau (switch inline, modale Créer /
     * Modifier) : réactiver repose toujours une échéance fraîche, y compris si
     * la précédente était déjà passée.
     */
    public function setGpsBypass(bool $active): void
    {
        $this->update([
            'gps_bypass' => $active,
            'gps_bypass_expires_at' => $active
                ? now()->addHours(self::GPS_BYPASS_TTL_HOURS)
                : null,
        ]);
    }

    /**
     * Dérogations expirées : drapeau encore `true` mais échéance atteinte —
     * à remettre à `false` par `gps:expire-bypass` (cron).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiredGpsBypass(Builder $query): Builder
    {
        return $query
            ->where('gps_bypass', true)
            ->whereNotNull('gps_bypass_expires_at')
            ->where('gps_bypass_expires_at', '<=', now());
    }

    /**
     * Profil employé du compte — `null` pour un admin / super admin.
     * Génériques explicites : la relation porte le périmètre « source » d'un
     * commercial (`employee.enterprise.source`), le chaînage doit rester
     * typé pour PHPStan.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function assignedClients()
    {
        return Client::whereHas('reservations', function ($q) {
            $q->where('comercial_id', $this->id)
                ->whereIn('status', Reservation::ACTIVE_STATUSES);
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
