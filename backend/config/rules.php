<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retour en liste après indisponibilité (mois)
    |--------------------------------------------------------------------------
    |
    | Nombre de mois après lequel un client marqué UNAVAILABLE revient
    | automatiquement en AVAILABLE (`returned_at`). Valeur partagée par
    | `CallWorkflowService` (NO / épuisement des BV) et l'import public
    | (`ClientImportService::bulkUnavailableFromPhone()`), qui n'ont donc
    | plus de dépendance de classe l'un envers l'autre.
    |
    */

    'non_block_months' => (int) env('RULES_NON_BLOCK_MONTHS', 3),

];
