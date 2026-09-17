<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::post('entreprises', [AdminController::class, 'creerEntreprise']);
    Route::get('liste-noire', [AdminController::class, 'listeNoire']);
    Route::post('liste-noire/{id}/debloquer', [AdminController::class, 'debloquerClient']);
});
