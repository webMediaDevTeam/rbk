<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `licence_propre_numero` : INT UNSIGNED -> BIGINT UNSIGNED.
 *
 * Le numéro « Licence (propre) » du RBQ est un nombre à 10 chiffres (jusqu'à
 * 9 999 999 999), alors que `unsignedInteger` plafonne à 4 294 967 295.
 * L'import massif via le webhook public `POST api/v1/clients/bulk-upsert`
 * rejetait donc **48 660 des 53 149 lignes** avec :
 *
 *   SQLSTATE[22003] 1264 Out of range value for column 'licence_propre_numero'
 *
 * (remonté sous le message générique « Conflit de données : numéro de licence
 * déjà utilisé par un autre client » par
 * `Client::bulkUpsertFromScraperPayload`, qui attrape tout `QueryException`).
 *
 * L'index UNIQUE `clients_licence_propre_numero_unique` est conservé tel quel
 * (un ALTER ... MODIFY ne touche pas aux index). Les valeurs déjà stockées
 * sont < 4 294 967 295, la conversion est donc sans perte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'licence_propre_numero')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('licence_propre_numero')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'licence_propre_numero')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('licence_propre_numero')->nullable()->change();
        });
    }
};
