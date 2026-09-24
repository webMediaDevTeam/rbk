<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Compteurs de tentatives (règle : >= 2 -> UNAVAILABLE_TEMP 21 jours)
            $table->unsignedInteger('bv_count')->default(0)->after('status');
            $table->unsignedInteger('injoinable_count')->default(0)->after('bv_count');

            // Plus aucune expiration de réservation
            $table->dropColumn('expires_at');

            // Statut : EN_ATTENT, OUI, NON, BV, INJOINABLE (INJOINABLE affiché "à RAPPELER")
            $table->string('status')->default('EN_ATTENT')->change();
        });

        // Anciennes valeurs ("RESERVED", ancien défaut "AVAILABLE") -> EN_ATTENT
        DB::table('reservations')
            ->whereIn('status', ['RESERVED', 'AVAILABLE'])
            ->update(['status' => 'EN_ATTENT']);

        // Réservations avec rappel planifié -> BV ou INJOINABLE selon le dernier outcome du couple
        // (client, commercial). Le client reste RESERVED, seul reservation.status change.
        DB::statement("
            UPDATE reservations SET status = (
                SELECT CASE co.outcome WHEN 'INJOINABLE' THEN 'INJOINABLE' ELSE 'BV' END
                FROM call_outcomes co
                WHERE co.client_id = reservations.client_id
                  AND co.comercial_id = reservations.comercial_id
                  AND co.outcome IN ('BOITE_VOCALE', 'BV', 'INJOINABLE')
                ORDER BY co.created_at DESC
                LIMIT 1
            )
            WHERE recall_at IS NOT NULL
              AND EXISTS (
                SELECT 1 FROM call_outcomes co
                WHERE co.client_id = reservations.client_id
                  AND co.comercial_id = reservations.comercial_id
                  AND co.outcome IN ('BOITE_VOCALE', 'BV', 'INJOINABLE')
              )
        ");

        // Alimente les compteurs de tentatives depuis l'historique d'appel
        DB::statement("
            UPDATE reservations SET
                bv_count = (
                    SELECT COUNT(*) FROM call_outcomes co
                    WHERE co.client_id = reservations.client_id
                      AND co.comercial_id = reservations.comercial_id
                      AND co.outcome IN ('BOITE_VOCALE', 'BV')
                ),
                injoinable_count = (
                    SELECT COUNT(*) FROM call_outcomes co
                    WHERE co.client_id = reservations.client_id
                      AND co.comercial_id = reservations.comercial_id
                      AND co.outcome = 'INJOINABLE'
                )
        ");
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // La colonne expires_at est recréée NULL (les dates sont perdues)
            $table->timestamp('expires_at')->nullable()->after('recall_at');
            $table->dropColumn(['bv_count', 'injoinable_count']);
            $table->string('status')->default('AVAILABLE')->change();
        });

        DB::table('reservations')
            ->whereIn('status', ['EN_ATTENT', 'BV', 'INJOINABLE', 'OUI', 'NON'])
            ->update(['status' => 'RESERVED']);
    }
};
