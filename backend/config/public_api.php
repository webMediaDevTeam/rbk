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

];
