<?php

use App\Http\Controllers\Api\V1\Shared\AuthController;
use App\Http\Controllers\Api\V1\Shared\CallLogController;
use App\Http\Controllers\Api\V1\Shared\DashboardController;
use App\Http\Controllers\Api\V1\Shared\ProspectFilterController;
use App\Http\Controllers\Api\V1\Shared\ProspectOverviewController;
use App\Http\Controllers\Api\V1\Shared\PublicClientController;
use App\Http\Controllers\Api\V1\Shared\RingCentralController;
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

// Conversion en liste noire par nom — endpoint public **temporaire**
// (spec docs/convert_to_blacklist_api.md) : même surface que les deux
// routes ci-dessus, aucune authentification, lot borné par
// `PUBLIC_API_MAX_ITEMS`. Recherche par `enterprise_name` / `name`
// insensible à la casse.
Route::post('clients/convert-to-blacklist', [PublicClientController::class, 'convertToBlacklist']);

// Indisponibilité en masse **par numéro de téléphone** — endpoint public
// **temporaire** (spec docs/convert_to_unavailable_api.md) : même surface
// que les routes ci-dessus, aucune authentification, lot borné par
// `PUBLIC_API_MAX_ITEMS`. Numéro détecté quel que soit son format
// (`819-418-6550` / `+1-819-418-6550` / `8194186550`…), chaque ligne
// visée → `UNAVAILABLE` + `returned_at = now + 3 mois` (geste NO).
Route::post('clients/convert-to-unavailable', [PublicClientController::class, 'convertToUnavailable']);

// Fausses réservations « NON » (endpoint public **temporaire**, spec
// docs/create_no_reservations_api.md) : même surface que les routes
// ci-dessus, aucune authentification, lot borné par
// `PUBLIC_API_MAX_ITEMS` (mode `{"status": …}` : borné par
// `NO_RESERVATIONS_STATUS_LIMIT`). Une ligne `reservations` `NO` par client
// visé, attribuée à **un seul employé**, sans effet de bord métier.
Route::post('clients/create-no-reservations', [PublicClientController::class, 'createNoReservations']);

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

// ── Appareils RingCentral (libellé = numéro) — ADMIN + SUPER_ADMIN ──────
// Consultation seule : nécessaire au select « Appareil / numéro source »
// des modales employé (création / édition), ouvertes à l'ADMIN comme au
// SUPER_ADMIN. Le reste du contrôle d'appel reste réservé SUPER_ADMIN.
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN'])
    ->get('call-logs/devices', [RingCentralController::class, 'devices']);

// ── Journal d'appels d'un employé + lecture d'enregistrement ────────────
// Onglet « Appels » de la fiche `/comercialDetail/:id` (ADMIN + SUPER_ADMIN,
// même périmètre que les modales employé) : le journal est résolu **via
// l'appareil de l'employé** (`employees.ringcentral_device_id`), et
// l'enregistrement est relayé par un proxy car le `contentUri` RingCentral
// exige l'en-tête `Authorization` qu'un `<audio>` ne peut pas envoyer.
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('call-logs/employees/{id}/logs', [RingCentralController::class, 'employeeLogs']);
    Route::get('call-logs/recordings/{recordingId}/content', [RingCentralController::class, 'recordingContent']);
});

// ── RingCentral — contrôle d'appel (PHASE DE TEST, Super Admin) ────────
// Pass-through vers RingCentral, **aucune écriture en base** pour l'instant :
// la synchronisation (account_id, colonnes `ringcentral_*` de `users` pour
// les seuls COMERCIAL, call logs dé-doublonnés) viendra au passage au réel —
// docs/TODOS.md « Phase 6bis ». Visible depuis `/call-logs-test`.
Route::middleware(['auth:sanctum', CheckRole::class.':SUPER_ADMIN'])->group(function () {
    Route::get('call-logs/account', [RingCentralController::class, 'account']);
    Route::post('call-logs/call', [RingCentralController::class, 'makeCall']);
    Route::get('call-logs/calls/{sessionId}', [RingCentralController::class, 'callStatus']);
    Route::get('call-logs/calls/{sessionId}/parties/{partyId}/recordings', [RingCentralController::class, 'recordings']);
    Route::delete('call-logs/calls/{sessionId}', [RingCentralController::class, 'hangUp']);
});

// ── Démarrage d'enregistrement d'une partie — SUPER_ADMIN + COMERCIAL ────
// Le COMERCIAL en a besoin pour **ses propres appels** (`POST /call-logs/my-call`
// envoyé avec `record: true`) : la réponse part avant que la partie soit
// connectée, le navigateur retente donc `…/record` jusqu'au succès.
Route::middleware(['auth:sanctum', CheckRole::class.':COMERCIAL,ADMIN,SUPER_ADMIN'])
    ->post('call-logs/calls/{sessionId}/parties/{partyId}/record', [RingCentralController::class, 'record']);

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
