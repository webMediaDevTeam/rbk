<?php

/**
 * Geofencing de la connexion (voir GeofenceService).
 *
 * `enabled` est le coupe-circuit global : à `false`, personne n'est jamais
 * bloqué (utile tant que la prod n'est pas en HTTPS — la géolocalisation
 * navigateur exige un contexte sécurisé).
 */
return [

    /**
     * Coupe-circuit global. **Par défaut `false` (éteint)** : la
     * géolocalisation navigateur exige un contexte sécurisé (HTTPS ou
     * `localhost`). Tant que l'origine servie n'est pas sûre, l'activer
     * refuserait toute connexion COMERCIAL / OPERATOR sans dérogation
     * (`LOCATION_REQUIRED`). Mettre `GEOFENCE_ENABLED=true` uniquement après
     * passage en HTTPS (ou en local via `localhost`).
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
