<?php

/*
|--------------------------------------------------------------------------
| Webhook public d'import de clients
|--------------------------------------------------------------------------
|
| `POST api/v1/clients/bulk-upsert` (docs/RULES.md §12) est appelé SANS
| authentification : la taille d'un lot est donc bornée côté serveur.
|
*/

return [

    // Nombre maximal d'enregistrements acceptés en un seul appel.
    'max_items' => (int) env('PUBLIC_API_MAX_ITEMS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Employé attributaire des fausses réservations « NON »
    |--------------------------------------------------------------------------
    |
    | `POST clients/create-no-reservations` écrit une réservation `NO` par
    | client visé. Elle est attribuée à **un seul** employé — pas à tous les
    | commerciaux : c'est le but de l'endpoint (fabriquer un historique
    | « cet employé a répondu NON »). La valeur par défaut est le
    | commercial du jeu de données de développement ; en production, pointer
    | sur le commercial voulu.
    |
    */
    'no_reservations_comercial_email' => env(
        'PUBLIC_API_NO_RESERVATIONS_COMERCIAL_EMAIL',
        'mohamed.khemir@apex-structures.tn'
    ),

    /*
    |--------------------------------------------------------------------------
    | Plafond du mode « périmètre » ({"status": …})
    |--------------------------------------------------------------------------
    |
    | Ce mode ne reçoit pas de liste : il sélectionne lui-même les clients du
    | statut demandé, par pages de `id` croissant. Au-delà du plafond, la
    | réponse renvoie `next_after` et le script rappelle l'API avec
    | `{"status": …, "after": "<uuid>"}` pour dérouler la suite.
    |
    */
    'no_reservations_status_limit' => (int) env(
        'PUBLIC_API_NO_RESERVATIONS_STATUS_LIMIT',
        20000
    ),

];
