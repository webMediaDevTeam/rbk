<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // « Privilège de libération » : drapeau posé par l'admin depuis
            // la modale Commercial (créer / éditer). Un COMERCIAL sans le
            // drapeau reçoit 403 sur `POST clients/{id}/blacklist` et
            // `POST reservations/release-pending` (middleware
            // `CheckPermission`) ; les ADMIN / SUPER_ADMIN passent toujours
            // (docs/RULES.md §7.1).
            $table->boolean('has_permission')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_permission');
        });
    }
};
