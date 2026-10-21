<?php

/**
 * Geofencing de la connexion (voir GeofenceService).
 *
 * `enabled` est la valeur de **repli** du coupe-circuit global : l'interrupteur
 * de la page « GPS » (préférence en base `geofence.enabled`, table `settings`)
 * prime dès qu'il a été posé. Éteint, personne n'est jamais bloqué — et la page
 * de connexion ne demande plus la position au navigateur.
 */
return [

    /**
     * Coupe-circuit global, **tant que l'administrateur n'a pas touché à
     * l'interrupteur de la page « GPS »** (`GeofenceService::isEnabled()` lit la
     * préférence `geofence.enabled` en base, puis retombe ici). **Par défaut
     * `false` (éteint)** : la géolocalisation navigateur exige un contexte
     * sécurisé (HTTPS ou `localhost`). Tant que l'origine servie n'est pas
     * sûre, l'activer refuserait toute connexion COMERCIAL / OPERATOR sans
     * dérogation (`LOCATION_REQUIRED`).
     */
    'enabled' => env('GEOFENCE_ENABLED', false),

    // Rejet des relevés trop imprécis (mètres). Le navigateur fournit
    // `position.coords.accuracy` ; au-delà, la position est considérée
    // non fiable et la connexion est refusée aux rôles contraints.
    'max_accuracy_m' => (int) env('GEOFENCE_MAX_ACCURACY_M', 100),

    // Rôles contrôlés à la connexion. ADMIN / SUPER_ADMIN ne sont jamais
    // bloqués, sans liste noire de rôles : tout rôle absent d'ici passe.
    'enforced_roles' => [
        'COMERCIAL',
        'OPERATOR',
    ],
];
