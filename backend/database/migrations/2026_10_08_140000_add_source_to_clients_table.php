<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origine du prospect — **même répertoire `sources` que les entreprises**
     * (table sans CRUD, lue par `GET /api/v1/sources`), mais ici pas de
     * saisie UI : la valeur arrive du payload n8n (clé « Source ») ou reste
     * sur le défaut.
     *
     *   - `NOT NULL` + `DEFAULT 'Affaire'` : ajouter la colonne remplit les
     *     lignes existantes avec « Affaire » (et SQLite fait de même au
     *     `migrate` des tests) — aucun client ne reste sans source ;
     *   - la création par `ClientImportService` pose `Client::DEFAULT_SOURCE`
     *     quand le payload n'envoie rien, et un payload sans « Source »
     *     (ou vide) **ne modifie jamais** la valeur déjà en place.
     *
     * Aucune donnée supprimée : `php artisan migrate` uniquement.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('source', 255)->default('Affaire')->after('representative_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
