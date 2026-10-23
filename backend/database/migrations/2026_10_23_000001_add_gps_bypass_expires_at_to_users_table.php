<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dérogation GPS **limitée dans le temps** : `users.gps_bypass` n'est plus
 * accordée sans échéance. La colonne `gps_bypass_expires_at` porte l'instant
 * de fin — posé à `now + 24 h` à chaque activation (switch inline de la page
 * Employés ou modale Commercial), remis à `null` à chaque désactivation.
 *
 * Aucune suppression de données : la colonne est nullable et les comptes déjà
 * en dérogation (`gps_bypass = true`, échéance `null`) restent couverts — une
 * échéance `null` vaut « accordée sans date de fin » côté serveur
 * (`User::isGpsBypassActive()`), ce qui préserve le comportement existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('gps_bypass_expires_at')
                ->nullable()
                ->after('gps_bypass');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gps_bypass_expires_at');
        });
    }
};
