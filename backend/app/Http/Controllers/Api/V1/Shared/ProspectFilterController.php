<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

class ProspectFilterController extends Controller
{
    /**
     * Distinct filter values (municipalities + categories) read straight from
     * the clients table — no dependency on the categories table.
     *
     * GET /filters
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Client::getUniqueCategoriesAndMunicipalities(),
        ]);
    }

    /**
     * Catégories distinctes (libellés de `clients.categories`), sans doublons.
     *
     * GET /categories
     */
    public function categories(): JsonResponse
    {
        return $this->values('categories');
    }

    /**
     * Municipalités distinctes, sans doublons.
     *
     * GET /municipalities
     */
    public function municipalities(): JsonResponse
    {
        return $this->values('municipality');
    }

    /**
     * Régions administratives distinctes, sans doublons.
     *
     * GET /administrative-regions
     */
    public function administrativeRegions(): JsonResponse
    {
        return $this->values('administrative_region');
    }

    /** @param  string  $column  une des colonnes de Client::DISTINCT_COLUMNS */
    private function values(string $column): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Client::distinctValues($column),
        ]);
    }
}
