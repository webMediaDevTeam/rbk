<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GpsZone;
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
            'radius_m' => ['sometimes', 'integer', 'between:50,20000'],
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
}
