<?php

use App\Http\Controllers\Api\V1\Shared\AuthController;
use App\Http\Controllers\Api\V1\Shared\CallLogController;
use App\Http\Controllers\Api\V1\Shared\DashboardController;
use App\Http\Controllers\Api\V1\Shared\ProspectFilterController;
use App\Http\Controllers\Api\V1\Shared\ProspectOverviewController;
use App\Http\Controllers\Api\V1\Shared\PublicClientController;
use App\Http\Controllers\Api\V1\Shared\UserController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

// ── Public Auth ──────────────────────────────────────────────
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/login/otp', [AuthController::class, 'sendLoginOtp']);
Route::post('auth/login/otp/verify', [AuthController::class, 'verifyLoginOtp']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/forgot-password/verify', [AuthController::class, 'verifyForgotPasswordOtp']);
Route::post('auth/forgot-password/reset', [AuthController::class, 'resetForgotPassword']);
Route::post('auth/verify-account', [AuthController::class, 'verifyAccount']);
Route::post('auth/resend-verification', [AuthController::class, 'resendVerification']);

// ── Public : import de prospects (webhook scraper / n8n) ────
// AUCUNE authentification (spec docs/public_api.md, règles RULES.md §12) :
// CORS couvert par `config/cors.php` (`paths` = `api/*`), lot borné par
// `public_api.max_items`. Déclarée avant toute route `clients/{…}` pour
// ne jamais être capturée par un paramètre de route.
Route::post('clients/bulk-upsert', [PublicClientController::class, 'bulkUpsert']);

// Suppression en masse, même règle de sécurité de bout en bout : un client
// qui porte des données liées (réservations / notes / rappels, toutes en
// `cascadeOnDelete`) est **ignoré**, la boucle passe au client suivant
// (RULES §12). `POST` et `DELETE` pointent sur la même action.
Route::match(['post', 'delete'], 'clients/bulk-delete', [PublicClientController::class, 'bulkDelete']);

// ── Authenticated: All roles ────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'deconnexion']);
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);
    Route::post('auth/profile/password/otp', [AuthController::class, 'sendPasswordOtp']);
    Route::post('auth/profile/password/otp/verify', [AuthController::class, 'verifyPasswordOtp']);
    Route::put('auth/profile/password', [AuthController::class, 'updateProfilePassword']);

    // Distinct values (no repetitions) read from the clients table,
    // cached 1 week server-side — Client::distinctValues().
    Route::get('filters', [ProspectFilterController::class, 'index']);          // {categories, municipalities, administrative_regions}
    Route::get('categories', [ProspectFilterController::class, 'categories']);  // list<string>
    Route::get('municipalities', [ProspectFilterController::class, 'municipalities']);
    Route::get('administrative-regions', [ProspectFilterController::class, 'administrativeRegions']);

    // Cartes KPI « Overview » des deux listes de prospects (chiffres
    // globaux). Déclarée avant `clients/{id}` (routes/api/commercial.php) :
    // les routes se lisent dans l'ordre de chargement des fichiers.
    Route::get('clients/overview', [ProspectOverviewController::class, 'overview']);

    // ── RingCentral / Call Logs ──────────────────────────────────
    Route::get('call-logs/users', [CallLogController::class, 'users']);
    Route::get('call-logs/users/{extensionId}', [CallLogController::class, 'userCalls']);
    Route::get('call-logs/by-phone/{phone}', [CallLogController::class, 'callsToNumber']);
});

// ── User Management ─────────────────────────────────────────
// ADMIN       → manages COMERCIAL
// SUPER_ADMIN → manages everyone
Route::middleware(['auth:sanctum', CheckRole::class.':COMERCIAL,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{id}', [UserController::class, 'show']);
    // A user may update their own avatar (self-only enforced in UserController::canAct).
    Route::post('users/{id}/avatar', [UserController::class, 'updateAvatar']);
});

Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{id}', [UserController::class, 'update']);
    Route::patch('users/{id}/status', [UserController::class, 'toggleStatus']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);
});
