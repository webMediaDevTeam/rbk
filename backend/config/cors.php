<?php

/*
|--------------------------------------------------------------------------
| Origines autorisées (CORS)
|--------------------------------------------------------------------------
| La liste vient du .env : `CORS_ALLOWED_ORIGINS`, séparées par des virgules.
|
|   CORS_ALLOWED_ORIGINS=http://localhost:5173,http://51.255.192.50
|
| Attention : une origine n'a JAMAIS de slash final.
|   correct : http://localhost:5173
|   faux    : http://localhost:5173/   (ne correspond à rien, d'où l'erreur
|                                       "CORS header does not match")
*/

return [
    'paths' => [
        'api/*',
        // Webhook public d'import (RULES §12) : couvert par `api/*`, listé
        // ici pour tracer le endpoint ouvert sans authentification.
        'api/v1/clients/bulk-upsert',
        // Conversion en liste noire par nom (endpoint public temporaire) :
        // couvert par `api/*`, listé ici pour tracer la route ouverte.
        'api/v1/clients/convert-to-blacklist',
        // Indisponibilité en masse par numéro de téléphone (endpoint public
        // temporaire) : couvert par `api/*`, listé ici pour tracer la
        // route ouverte.
        'api/v1/clients/convert-to-unavailable',
        'sanctum/csrf-cookie',
        'login',
        'logout',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
