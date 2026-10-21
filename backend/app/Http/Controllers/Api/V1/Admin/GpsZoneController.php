<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GpsZone;
use App\Models\Setting;
use App\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD des zones de géorepérage pour la page admin « GPS ».
 *
 * Routes déclarées dans le groupe ADMIN / SUPER_ADMIN
 * (`routes/api/admin.php`) — aucun accès pour COMERCIAL / OPERATOR.
 */
class GpsZoneController extends Controller
{
    /**
     * Règles communes création / mise à jour. `name` et `is_active` sont
     * obligatoires à la création, optionnels en `update` (PATCH-like `PUT`).
     */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'latitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'longitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'radius_m' => ['sometimes', 'integer', 'between:50,1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function index(): JsonResponse
    {
        // Second critère `id` : `created_at` est à la seconde — deux zones
        // créées dans la même requête seraient sinon dans un ordre aléatoire.
        return response()->json([
            'zones' => GpsZone::query()->latest()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: true));

        $zone = GpsZone::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Zone créée avec succès.',
            // `fresh()` : les colonnes à défaut SQL (`is_active`, `radius_m`)
            // ne sont pas peuplées sur le modèle fraîchement créé.
            'zone' => $zone->fresh(),
        ], 201);
    }

    public function update(Request $request, GpsZone $zone): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: false));

        $zone->update($validated);

        return response()->json([
            'message' => 'Zone mise à jour.',
            'zone' => $zone->fresh(),
        ]);
    }

    public function destroy(Request $request, GpsZone $zone): JsonResponse
    {
        $zone->delete();

        return response()->json([
            'message' => 'Zone supprimée.',
        ]);
    }

    /**
     * GET /gps/settings — réglages **globaux** du géorepérage, lus par la page
     * « GPS » : l'état du coupe-circuit et le nombre de zones actives (la page
     * avertit qu'activer sans aucune zone active bloquerait tous les comptes
     * contraints).
     */
    public function settings(): JsonResponse
    {
        return response()->json([
            'geofence_enabled' => GeofenceService::isEnabled(),
            'active_zones' => GpsZone::query()->where('is_active', true)->count(),
            'enforced_roles' => (array) config('geofence.enforced_roles', []),
        ]);
    }

    /**
     * PUT /gps/settings — bascule le coupe-circuit global.
     *
     * Éteint, `GeofenceService::check()` refuse personne : les commerciaux et
     * opérateurs se connectent de n'importe où, et la page de connexion ne
     * demande plus la position. La valeur est stockée en base (`Setting`), pas
     * dans la configuration : elle survit à un redéploiement et n'a pas besoin
     * de `config:clear`.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'geofence_enabled' => ['required', 'boolean'],
        ]);

        Setting::setBool(Setting::GEOFENCE_ENABLED, filter_var($validated['geofence_enabled'], FILTER_VALIDATE_BOOLEAN));

        $enabled = GeofenceService::isEnabled();

        return response()->json([
            'message' => $enabled
                ? 'Contrôle GPS à la connexion activé.'
                : 'Contrôle GPS à la connexion désactivé.',
            'geofence_enabled' => $enabled,
        ]);
    }
}
