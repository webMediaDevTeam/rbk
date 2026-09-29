<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notes.reservation_id` : l'événement `RESERVED` rattache sa note à la
 * réservation qu'il vient de créer (cas 1 — création initiale).
 *
 * Colonne nullable : seules les notes de réservation la portent. La clé
 * étrangère n'est créée que là où le moteur la supporte (MySQL) : SQLite
 * ignore `compileForeign` sur une table existante — les tests continuent de
 * tourner sur un colonne sans contrainte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->uuid('reservation_id')->nullable()->after('client_id');
            $table->foreign('reservation_id')
                ->references('id')->on('reservations')
                ->cascadeOnDelete();
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            // FK d'abord (MySQL refuse de retirer un index encore référencé).
            $table->dropForeign(['reservation_id']);
            $table->dropIndex(['reservation_id']);
            $table->dropColumn('reservation_id');
        });
    }
};
