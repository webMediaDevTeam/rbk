<?php

use App\Http\Controllers\Api\V1\Commercial\ClientController;
use App\Http\Controllers\Api\V1\Commercial\NoteController;
use App\Http\Controllers\Api\V1\Commercial\OutcomeController;
use App\Http\Controllers\Api\V1\Commercial\ReminderController;
use App\Http\Controllers\Api\V1\Commercial\ReservationController;
use App\Http\Controllers\Api\V1\Commercial\ReservationGroupController;
use App\Http\Controllers\Api\V1\Shared\RingCentralController;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class.':COMERCIAL'])->group(function () {
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/mes', [ClientController::class, 'mine']);
    Route::get('clients/{id}', [ClientController::class, 'show']);
    // Mise en liste noire : ouverte à **tous** les commerciaux (plus de
    // portail « Privilège de libération » sur cette action — le privilège
    // ne protège plus que la libération de la liste ci-dessous).
    Route::post('clients/{id}/blacklist', [ClientController::class, 'blacklist']);
    Route::get('clients/{clientId}/notes', [NoteController::class, 'index']);
    Route::post('notes', [NoteController::class, 'store']);
    Route::delete('notes/{id}', [NoteController::class, 'destroy']);

    Route::post('clients/{clientId}/outcome', [OutcomeController::class, 'store']);

    Route::get('reminders', [ReminderController::class, 'index']);
    Route::get('reminders/count', [ReminderController::class, 'count']);
    // Marquer un rappel comme terminé (+ note facultative dans l'historique)
    Route::post('reminders/{id}/done', [ReminderController::class, 'done']);

    Route::get('reservation-groups', [ReservationGroupController::class, 'index']);
    Route::get('reservation-groups/{id}', [ReservationGroupController::class, 'show']);
    Route::post('clients/reserver', [ReservationController::class, 'store']);

    // Compteur header : réservations actives du commercial connecté
    Route::get('reservations/active-count', [ReservationController::class, 'activeCount']);
    // « Libérer la liste » : les prospects encore « en attente »
    // redeviennent AVAILABLE (modale « Réserver » d'un nouveau lot).
    // Privilège de libération requis (`users.has_permission`) — 403 sinon.
    Route::post('reservations/release-pending', [ReservationController::class, 'releasePending'])
        ->middleware(CheckPermission::class);

    // Appel sortant de l'employé (bouton « Appeler » Mes listes / Rappels / BV) :
    // `from` est résolu côté API dans `employees.ringcentral_from_number`,
    // le navigateur n'envoie que la destination `to`.
    Route::post('call-logs/my-call', [RingCentralController::class, 'callAsEmployee']);
    // Le détail / l'écoute d'un appel (`call-logs/client-calls/…`) n'est plus
    // ouvert aux COMERCIAL : voir `routes/api/shared.php` (ADMIN / SUPER_ADMIN).
});

// Renommage de liste : propriétaire (COMERCIAL) ou ADMIN / SUPER_ADMIN.
// Route hors groupe COMERCIAL pur pour que le contrôleur puisse autoriser un admin.
Route::middleware(['auth:sanctum', CheckRole::class.':COMERCIAL,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::patch('reservation-groups/{id}', [ReservationGroupController::class, 'update']);
});

// Mise à jour d'une fiche client (`PATCH clients/{id}`) : **ouverte à tout
// utilisateur authentifié** — la restriction « réservation active du titulaire »
// a été retirée du contrôleur, donc n'importe quel rôle édite n'importe quelle
// fiche. Déclarée hors du groupe `COMERCIAL` pour que l'`ADMIN` / `OPERATOR` y
// accède aussi ; le verbe `PATCH` ne heurte pas le `GET clients/{id}` commercial.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::patch('clients/{id}', [ClientController::class, 'update']);
});
