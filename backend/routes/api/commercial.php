<?php

use App\Http\Controllers\Api\V1\Commercial\ClientController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':COMERCIAL'])->group(function () {
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/{id}', [ClientController::class, 'show']);
    Route::post('clients/reserver', [\App\Http\Controllers\Api\V1\Commercial\ReservationController::class, 'store']);
    Route::get('clients/mes', [ClientController::class, 'mine']);
});