<?php

use Illuminate\Support\Facades\Route;

// Apply logging middleware to all v1 API routes to aid debugging
Route::prefix('v1')->middleware('log.http')->group(function () {
    require __DIR__ . '/api/shared.php';
    require __DIR__ . '/api/superAdmin.php';
    require __DIR__ . '/api/admin.php';
    require __DIR__ . '/api/entreprise.php';
    require __DIR__ . '/api/commercial.php';
});
