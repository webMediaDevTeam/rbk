<?php

namespace App\Services;

use App\Models\GpsZone;
use App\Models\Setting;
use App\Models\User;

/**
 * Contrôle de géorepérage à la connexion (voir `config/geofence.php`).
 *
 * La décision se prend **côté serveur** : le frontend ne fait que transmettre
 * la position fournie par le navigateur. Un rôle contraint
 * (`config('geofence.enforced_roles')`) sans dérogation active doit fournir
 * une position précise (`accuracy <= max_accuracy_m`) tombant dans au moins
 * une zone active (`distance <= radius_m`, Haversine).
 *
 * La dérogation (`users.gps_bypass`) est **limitée à 24 h** : seule
 * `User::isGpsBypassActive()` (drapeau posé ET échéance non atteinte) ouvre la
 * connexion hors zone — une dérogation expirée bloque donc immédiatement, sans
 * attendre le cron `gps:expire-bypass` qui remet le drapeau à `false`.
 */
class GeofenceService
{
    private const EARTH_RADIUS_M = 6371000.0;

    /**
     * Coupe-circuit **effectif** du géorepérage.
     *
     * La préférence posée en base (interrupteur « Contrôle GPS à la connexion »
     * de la page « GPS », clé `Setting::GEOFENCE_ENABLED`) a la main dès qu'elle
     * existe ; sinon on retombe sur `config('geofence.enabled')` (la variable
     * d'environnement `GEOFENCE_ENABLED`), **éteinte par défaut**. Un
     * administrateur peut donc activer et désactiver le contrôle sans
     * redéploiement, et un environnement neuf démarre sans géorepérage.
     *
     * Lu aussi par la page de connexion (route publique) : éteint, elle ne
     * demande plus la position au navigateur.
     */
    public static function isEnabled(): bool
    {
        return Setting::bool(
            Setting::GEOFENCE_ENABLED,
            (bool) config('geofence.enabled', false),
        );
    }

    /**
     * @return array{allowed: bool, code?: string, message?: string}
     */
    public function check(User $user, ?float $lat, ?float $lng, ?float $accuracy): array
    {
        if (! self::isEnabled()) {
            return ['allowed' => true];
        }

        $enforced = (array) config('geofence.enforced_roles', []);

        // Rôle non contraint (ADMIN / SUPER_ADMIN / tout rôle absent) : jamais bloqué.
        if (! in_array($user->role, $enforced, true)) {
            return ['allowed' => true];
        }

        // Dérogation accordée par un gestionnaire : connexion de n'importe où,
        // **tant qu'elle n'a pas expiré** (24 h après l'activation).
        if ($user->isGpsBypassActive()) {
            return ['allowed' => true];
        }

        if ($lat === null || $lng === null) {
            return [
                'allowed' => false,
                'code' => 'LOCATION_REQUIRED',
                'message' => 'Votre position est requise pour vous connecter. Autorisez la géolocalisation.',
            ];
        }

        $maxAccuracy = (int) config('geofence.max_accuracy_m', 100);

        if ($accuracy !== null && $accuracy > $maxAccuracy) {
            return [
                'allowed' => false,
                'code' => 'LOW_ACCURACY',
                'message' => 'Votre position est trop imprécise. Réessayez en extérieur ou près d une fenêtre.',
            ];
        }

        $withinZone = GpsZone::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn (GpsZone $zone): bool => $this->distanceMeters(
                $lat,
                $lng,
                (float) $zone->latitude,
                (float) $zone->longitude,
            ) <= (float) $zone->radius_m);

        if (! $withinZone) {
            return [
                'allowed' => false,
                'code' => 'OUTSIDE_GEOFENCE',
                'message' => 'Vous êtes en dehors des zones autorisées.',
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Distance Haversine (mètres) entre deux points géographiques.
     */
    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $deltaLat = $latTo - $latFrom;
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }
}
