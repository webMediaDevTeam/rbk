<?php

use App\Http\Controllers\Api\V1\SuperAdmin\SuperAdminController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckRole::class . ':SUPER_ADMIN'])->group(function () {
    Route::post('admins', [SuperAdminController::class, 'creerAdmin']);
});
