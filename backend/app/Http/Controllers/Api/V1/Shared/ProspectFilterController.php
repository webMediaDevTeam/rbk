<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Source;
use App\Models\User;
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

    /**
     * Noms du répertoire `sources`, qui alimente les onglets « Tous / Affaire
     * / … » de la Grande liste admin (`GET /clients/sources`).
     */
    public function sources(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Source::query()->orderBy('name')->pluck('name')->all(),
        ]);
    }

    /**
     * Options des filtres « Entreprise » / « Employé » de la Grande liste.
     *
     * Un lien client ↔ employé passe par les notes : `notes.sender_id` est un
     * **utilisateur** commercial, rattaché à une entreprise via
     * `employees.enterprise_id`. On ne renvoie donc que :
     *
     *  - les commerciaux (rôle `COMERCIAL`) ayant **interagi au moins une
     *    fois** avec un client — auteurs d'au moins une note — identifiés par
     *    leur **id d'utilisateur** (clé du filtre `?employee_id=`) ;
     *  - les **entreprises** possédant au moins un de ces employés (clé du
     *    filtre `?enterprise_id=`).
     *
     * Le frontend filtre la liste d'employés par `enterprise_id` côté client :
     * « tous les employés ayant interagi ≥ 1 fois » sans entreprise, « ceux de
     * cette entreprise » une fois l'entreprise choisie.
     *
     * GET /clients/interaction-filters
     */
    public function interactionFilters(): JsonResponse
    {
        $senderIds = Note::query()->whereNotNull('sender_id')->distinct()->pluck('sender_id');

        $employees = User::query()
            ->whereIn('id', $senderIds)
            ->where('role', 'COMERCIAL')
            ->with('employee:id,user_id,enterprise_id,first_name,last_name')
            ->get()
            ->map(function (User $u): array {
                // Un commercial peut exister sans fiche `Employee` : on retombe
                // sur le nom / l'email du `User` (pas de nullsafe — Larastan
                // tient la relation eagerly-loaded pour non-nulle).
                $employee = $u->employee;
                $name = trim(($employee ? $employee->first_name.' '.$employee->last_name : $u->first_name.' '.$u->last_name));

                return [
                    'id' => $u->id,
                    'name' => $name !== '' ? $name : $u->email,
                    'enterprise_id' => $employee?->enterprise_id,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        $enterpriseIds = collect($employees)->pluck('enterprise_id')->filter()->unique()->all();

        $enterprises = Enterprise::query()
            ->whereIn('id', $enterpriseIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Enterprise $e): array => ['id' => $e->id, 'name' => $e->name])
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'enterprises' => $enterprises,
                'employees' => $employees,
            ],
        ]);
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
