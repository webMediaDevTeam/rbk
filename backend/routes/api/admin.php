<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\GpsZoneController;
use App\Http\Controllers\Api\V1\Shared\CommercialAdminController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

// Grande liste partagée avec l'`OPERATOR` : liste, détail et mise à jour de
// fiche. Le périmètre visible de l'opérateur (« Disponible sans téléphone »)
// n'est pas décidé ici — il est forcé côté contrôleur
// (`CommercialAdminController::applyOperatorScope()`), donc aucun filtre
// envoyé par l'appelant ne peut l'élargir. La définition de ces routes passe
// avant `commercials/{id}` (MANAGERS) pour que `commercials/clients` ne soit
// jamais capturé par ce dernier.
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN,OPERATOR'])->group(function () {
    Route::get('commercials/clients', [CommercialAdminController::class, 'clients']);
    Route::get('commercials/clients/{id}', [CommercialAdminController::class, 'client']);
    // Mise à jour **complète** d'une fiche client : identité, contact,
    // licence, source. `PUT` (remplacement des champs fournis) ; l'import
    // scraper/n8n est coupé par `is_manually_updated`.
    Route::put('commercials/clients/{id}', [CommercialAdminController::class, 'update']);
    // Ajout / modification du numéro d'un client (statut « Sans téléphone »).
    Route::patch('commercials/clients/{id}/phone', [CommercialAdminController::class, 'updatePhone']);
});

// Reste réservé aux gestionnaires (ADMIN / SUPER_ADMIN) : liste noire,
// débloquage, mise au trou d'un client et listes d'employés/commerciaux.
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('liste-noire', [AdminController::class, 'listeNoire']);
    Route::post('liste-noire/{id}/debloquer', [AdminController::class, 'debloquerClient']);
    Route::get('commercials', [CommercialAdminController::class, 'index']);
    // Compteurs par employé (puces Clients / BV / Rappel / Oui de la page
    // « Employés ») — déclarée AVANT `commercials/{id}`, sinon « stats »
    // serait avalé par le segment d'identifiant.
    Route::get('commercials/stats', [CommercialAdminController::class, 'stats']);
    Route::post('commercials/clients/{id}/blacklist', [CommercialAdminController::class, 'blacklist']);
    Route::get('commercials/{id}', [CommercialAdminController::class, 'show']);

    // Zones de géorepérage (page admin « GPS ») — création / édition /
    // suppression des cercles contrôlés à la connexion (GeofenceService).
    Route::get('gps/zones', [GpsZoneController::class, 'index']);
    Route::post('gps/zones', [GpsZoneController::class, 'store']);
    Route::put('gps/zones/{zone}', [GpsZoneController::class, 'update']);
    Route::delete('gps/zones/{zone}', [GpsZoneController::class, 'destroy']);

    // Coupe-circuit **global** du contrôle GPS à la connexion (interrupteur en
    // haut de la page « GPS ») — stocké en base, pas dans la configuration.
    Route::get('gps/settings', [GpsZoneController::class, 'settings']);
    Route::put('gps/settings', [GpsZoneController::class, 'updateSettings']);
});
