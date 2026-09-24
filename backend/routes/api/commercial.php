<?php

use App\Http\Controllers\Api\V1\Commercial\ClientController;
use App\Http\Controllers\Api\V1\Commercial\NoteController;
use App\Http\Controllers\Api\V1\Commercial\OutcomeController;
use App\Http\Controllers\Api\V1\Commercial\ReminderController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':COMERCIAL'])->group(function () {
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/mes', [ClientController::class, 'mine']);
    Route::get('clients/{id}', [ClientController::class, 'show']);
    Route::post('clients/{id}/blacklist', [ClientController::class, 'blacklist']);
    Route::get('clients/{clientId}/notes', [NoteController::class, 'index']);
    Route::post('notes', [NoteController::class, 'store']);
    Route::delete('notes/{id}', [NoteController::class, 'destroy']);

    Route::post('clients/{clientId}/outcome', [OutcomeController::class, 'store']);

    Route::get('reminders', [ReminderController::class, 'index']);
    Route::get('reminders/count', [ReminderController::class, 'count']);

    Route::get('reservation-groups', [\App\Http\Controllers\Api\V1\Commercial\ReservationGroupController::class, 'index']);
    Route::get('reservation-groups/{id}', [\App\Http\Controllers\Api\V1\Commercial\ReservationGroupController::class, 'show']);
    Route::post('clients/reserver', [\App\Http\Controllers\Api\V1\Commercial\ReservationController::class, 'store']);

    // Compteur header : réservations actives du commercial connecté
    Route::get('reservations/active-count', [\App\Http\Controllers\Api\V1\Commercial\ReservationController::class, 'activeCount']);
});

// Renommage de liste : propriétaire (COMERCIAL) ou ADMIN / SUPER_ADMIN.
// Route hors groupe COMERCIAL pur pour que le contrôleur puisse autoriser un admin.
Route::middleware(['auth:sanctum', CheckRole::class . ':COMERCIAL,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::patch('reservation-groups/{id}', [\App\Http\Controllers\Api\V1\Commercial\ReservationGroupController::class, 'update']);
});
