<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\GpsZoneController;
use App\Http\Controllers\Api\V1\Shared\CommercialAdminController;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

// Grande liste partagée avec l'`OPERATOR` : **lecture** (liste, détail) et
// **blocage**. Le périmètre visible de l'opérateur (« Disponible sans téléphone »)
// n'est pas décidé ici — il est forcé côté contrôleur
// (`CommercialAdminController::applyOperatorScope()`), donc aucun filtre
// envoyé par l'appelant ne peut l'élargir. La définition de ces routes passe
// avant `commercials/{id}` (MANAGERS) pour que `commercials/clients` ne soit
// jamais capturé par ce dernier.
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN,OPERATOR'])->group(function () {
    Route::get('commercials/clients', [CommercialAdminController::class, 'clients']);
    Route::get('commercials/clients/{id}', [CommercialAdminController::class, 'client']);
    // Mise en liste noire **d'une fiche du périmètre de l'appelant**. L'`OPERATOR`
    // y a accès, mais en **blocage seulement** : la route est bornée à son
    // périmètre par `CommercialAdminController::findClientForRequest()` (404 hors
    // scope), et le **débloquage** (`liste-noire/{id}/debloquer`) reste réservé
    // aux gestionnaires dans le groupe ci-dessous — l'opérateur ne peut donc ni
    // débloquer une fiche, ni en toucher une hors de sa Grande liste.
    Route::post('commercials/clients/{id}/blacklist', [CommercialAdminController::class, 'blacklist']);
});

// Écriture d'une fiche client (« Modifier le client » / saisie du numéro) :
// **ouverte à tout utilisateur authentifié** — plus aucune restriction de rôle
// ni de périmètre sur la mise à jour (toute fiche est éditable par tous). La
// route `PUT`/`PATCH` ne peut être capturée par le `GET commercials/{id}` des
// groupes suivants (verbes et nombre de segments distincts).
Route::middleware(['auth:sanctum'])->group(function () {
    // Mise à jour **complète** d'une fiche client : identité, contact,
    // licence, source. `PUT` (remplacement des champs fournis) ; l'import
    // scraper/n8n est coupé par `is_manually_updated`.
    Route::put('commercials/clients/{id}', [CommercialAdminController::class, 'update']);
    // Ajout / modification du numéro d'un client (statut « Sans téléphone »).
    Route::patch('commercials/clients/{id}/phone', [CommercialAdminController::class, 'updatePhone']);
});

// Reste réservé aux gestionnaires (ADMIN / SUPER_ADMIN) : débloquage, mise au
// trou d'un client et listes d'employés/commerciaux. (Le **blocage** est partagé
// avec l'`OPERATOR` dans le groupe ci-dessus.)
Route::middleware(['auth:sanctum', CheckRole::class.':ADMIN,SUPER_ADMIN'])->group(function () {
    Route::get('liste-noire', [AdminController::class, 'listeNoire']);
    Route::post('liste-noire/{id}/debloquer', [AdminController::class, 'debloquerClient']);
    Route::get('commercials', [CommercialAdminController::class, 'index']);
    // Compteurs par employé (puces Clients / BV / Rappel / Oui de la page
    // « Employés ») — déclarée AVANT `commercials/{id}`, sinon « stats »
    // serait avalé par le segment d'identifiant.
    Route::get('commercials/stats', [CommercialAdminController::class, 'stats']);
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
