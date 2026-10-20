<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation de connexion hors zone GPS : `true` = l'utilisateur peut se
 * connecter de n'importe où (gérée par les rôles gestionnaires, colonne
 * « Connexion hors zones » de la page Employés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('gps_bypass')->default(false)->after('has_permission');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gps_bypass');
        });
    }
};
