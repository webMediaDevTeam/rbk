<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pointeur « réservation courante » du client :
 *
 *   clients.current_reservation_id — FK vers la dernière réservation (NULL si aucune)
 *   clients.current_comercial_id   — employé qui la détient (NULL si aucune)
 *
 * Une jointure remplace la sous-requête « dernière réservation » : la colonne
 * « Statut » des listes et les badges de filtre en déduisent **une seule
 * valeur** (Disponible / Oui / Non / BV / À rappeler / BlackList).
 *
 * Ces colonnes ne sont **jamais** écrites à la main : `Reservation` les
 * recalcule à chaque écriture (saved / deleted) via
 * `Client::syncCurrentReservation()`, et les rares suppressions massiques qui
 * contournent Eloquent l'appellent explicitement. Le backfill initialise les
 * données existantes avec la même règle que `latestReservation()`
 * (`created_at` DESC, `id` DESC).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignUuid('current_reservation_id')->nullable()->after('status')
                ->constrained('reservations')->nullOnDelete();
            $table->foreignUuid('current_comercial_id')->nullable()->after('current_reservation_id')
                ->constrained('users')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // SQLite (tests in-memory) ne sait pas supprimer une contrainte :
            // la colonne part seule, la FK disparaît avec la table à
            // `migrate:fresh`.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['current_reservation_id']);
                $table->dropForeign(['current_comercial_id']);
            }

            $table->dropColumn(['current_reservation_id', 'current_comercial_id']);
        });
    }

    /**
     * Une ligne par client : la réservation la plus récente (même ordre que
     * `Client::latestReservation()`), commercial compris. Les clients sans
     * réservation restent à NULL (valeur par défaut des colonnes).
     *
     * Écriture en PHP (et non en `UPDATE` corrélé avec alias) : la syntaxe
     * diffère entre MySQL et SQLite, et les deux drivers passent ici — les
     * tests rejouent la migration sur SQLite.
     */
    private function backfill(): void
    {
        $latest = [];

        DB::table('reservations')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'client_id', 'comercial_id'])
            ->each(function ($row) use (&$latest) {
                // Déjà trié du plus récent au plus ancien : le 1er vu gagne.
                $latest[$row->client_id] ??= $row;
            });

        foreach ($latest as $clientId => $row) {
            DB::table('clients')->where('id', $clientId)->update([
                'current_reservation_id' => $row->id,
                'current_comercial_id' => $row->comercial_id,
            ]);
        }
    }
};
