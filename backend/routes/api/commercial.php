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
    Route::get('clients/{clientId}/notes', [NoteController::class, 'index']);
    Route::post('notes', [NoteController::class, 'store']);
    Route::delete('notes/{id}', [NoteController::class, 'destroy']);

    Route::post('clients/{clientId}/outcome', [OutcomeController::class, 'store']);
    Route::post('clients/{clientId}/release', [OutcomeController::class, 'release']);

    Route::get('reminders', [ReminderController::class, 'index']);
    Route::get('reminders/count', [ReminderController::class, 'count']);

    Route::post('clients/reserver', [\App\Http\Controllers\Api\V1\Commercial\ReservationController::class, 'store']);
});
