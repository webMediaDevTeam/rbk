<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Source;
use Illuminate\Http\JsonResponse;

/**
 * Répertoire des « sources » — **liste seule, aucun CRUD** :
 * `GET /sources` est la *seule* route déclarée pour ce contrôleur
 * (`routes/api/shared.php`, groupe `auth:sanctum`).
 *
 * Utilisé par le sélecteur « Source » des modales « Créer / Modifier une
 * entreprise » ; les lignes viennent de `SourceSeeder`.
 */
class SourceController extends Controller
{
    /**
     * Sources triées par libellé → `{success, data: [{id, name}]}`.
     *
     * GET /sources
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Source::query()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
