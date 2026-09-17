<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__ . '/api/shared.php';
    require __DIR__ . '/api/superAdmin.php';
    require __DIR__ . '/api/admin.php';
    require __DIR__ . '/api/entreprise.php';
});
