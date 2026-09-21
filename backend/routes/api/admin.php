<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Shared\CommercialAdminController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('liste-noire', [AdminController::class, 'listeNoire']);
    Route::post('liste-noire/{id}/debloquer', [AdminController::class, 'debloquerClient']);
    Route::get('commercials', [CommercialAdminController::class, 'index']);
    Route::get('commercials/clients', [CommercialAdminController::class, 'clients']);
    Route::get('commercials/clients/{id}', [CommercialAdminController::class, 'client']);
    Route::post('commercials/clients/{id}/blacklist', [CommercialAdminController::class, 'blacklist']);
    Route::post('commercials/clients/{id}/unblock', [CommercialAdminController::class, 'unblock']);
    Route::get('commercials/{id}', [CommercialAdminController::class, 'show']);
});
