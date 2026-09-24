<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // blocked_until -> returned_at (compte à rebours Admin : 3 mois NON / 21 jours BV-Injoignable)
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('blocked_until', 'returned_at');
        });

        // Anciens statuts d'appel du client -> le client reste RESERVED.
        // La différenciation (BV / Injoignable) se fait via reservation.status.
        DB::table('clients')
            ->whereIn('status', ['VOICEMAIL', 'INJOINABLE'])
            ->update(['status' => 'RESERVED']);

        // Ancien comportement "OUI" (client réservé + outcome OUI) -> SUCCESS.
        DB::table('clients')
            ->where('status', 'RESERVED')
            ->whereIn('id', function ($q) {
                $q->select('co.client_id')
                    ->from('call_outcomes as co')
                    ->where('co.outcome', 'OUI');
            })
            ->update(['status' => 'SUCCESS']);

        // Anciens blocages 3 mois (blocked_until) -> UNAVAILABLE_TEMP avec returned_at conservé.
        DB::table('clients')
            ->where('status', 'AVAILABLE')
            ->whereNotNull('returned_at')
            ->update(['status' => 'UNAVAILABLE_TEMP']);
    }

    public function down(): void
    {
        // Best effort : les anciens statuts VOICEMAIL / INJOINABLE ne sont pas restaurables
        // de manière fiable (ils dépendaient de l'historique d'appel).
        DB::table('clients')->where('status', 'SUCCESS')->update(['status' => 'RESERVED']);
        DB::table('clients')->where('status', 'UNAVAILABLE_TEMP')->update(['status' => 'AVAILABLE']);

        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('returned_at', 'blocked_until');
        });
    }
};
