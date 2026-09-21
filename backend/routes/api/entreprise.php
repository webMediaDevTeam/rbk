<?php

use App\Http\Controllers\Api\V1\Entreprise\EnterpriseController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('enterprises', [EnterpriseController::class, 'index']);
    Route::post('enterprises', [EnterpriseController::class, 'store']);
    Route::get('enterprises/{id}', [EnterpriseController::class, 'show']);
    Route::put('enterprises/{id}', [EnterpriseController::class, 'update']);
    Route::delete('enterprises/{id}', [EnterpriseController::class, 'destroy']);
    Route::patch('enterprises/{id}/status', [EnterpriseController::class, 'toggleStatus']);
    Route::post('enterprises/{id}/logo', [EnterpriseController::class, 'updateLogo']);

    // Aliases
    Route::get('entreprises', [EnterpriseController::class, 'index']);
    Route::post('entreprises', [EnterpriseController::class, 'store']);
    Route::get('entreprises/{id}', [EnterpriseController::class, 'show']);
    Route::put('entreprises/{id}', [EnterpriseController::class, 'update']);
    Route::delete('entreprises/{id}', [EnterpriseController::class, 'destroy']);
    Route::patch('entreprises/{id}/status', [EnterpriseController::class, 'toggleStatus']);
    Route::post('entreprises/{id}/logo', [EnterpriseController::class, 'updateLogo']);
});
