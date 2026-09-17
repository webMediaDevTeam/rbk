<?php

use App\Http\Controllers\Api\V1\Shared\AuthController;
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

// Public: category list used by frontend filters
Route::get('categories', [\App\Http\Controllers\Api\V1\Shared\CategoryController::class, 'index']);

// ── Authenticated: All roles ────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'deconnexion']);
    Route::post('auth/profile/password/otp', [AuthController::class, 'sendPasswordOtp']);
    Route::post('auth/profile/password/otp/verify', [AuthController::class, 'verifyPasswordOtp']);
    Route::put('auth/profile/password', [AuthController::class, 'updateProfilePassword']);
});

// ── User Management ─────────────────────────────────────────
// ENTREPRISE → manages COMERCIAL (own enterprise only)
// ADMIN      → manages ENTREPRISE + COMERCIAL
// SUPER_ADMIN → manages everyone
Route::middleware(['auth:sanctum', CheckRole::class . ':COMERCIAL,ENTREPRISE,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('users',            [UserController::class, 'index']);
    Route::get('users/{id}',       [UserController::class, 'show']);
});

Route::middleware(['auth:sanctum', CheckRole::class . ':ENTREPRISE,ADMIN,SUPER_ADMIN'])->group(function () {
    Route::post('users',            [UserController::class, 'store']);
    Route::put('users/{id}',        [UserController::class, 'update']);
    Route::patch('users/{id}/status', [UserController::class, 'toggleStatus']);
    Route::post('users/{id}/avatar',  [UserController::class, 'updateAvatar']);
});

Route::middleware(['auth:sanctum', CheckRole::class . ':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::delete('users/{id}', [UserController::class, 'destroy']);
});
