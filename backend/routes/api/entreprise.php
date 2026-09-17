<?php

use App\Http\Controllers\Api\V1\Entreprise\EnterpriseController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':ENTREPRISE,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::post('commerciaux', [EnterpriseController::class, 'creerCommercial']);
    Route::get('commerciaux/statistiques', [EnterpriseController::class, 'statistiquesCommerciaux']);
});
