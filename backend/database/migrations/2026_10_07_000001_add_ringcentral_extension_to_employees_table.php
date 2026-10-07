<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase « Lier les employés à leurs numéros RingCentral » (docs/TODOS.md
 * « Sync Users ») : au-delà de l'appareil choisi à la main, la fiche
 * conserve maintenant la **correspondance serveur** établie par la synchro
 * (`POST /call-logs/sync/employees`) :
 *
 *   - `ringcentral_extension_id`    : poste RingCentral de l'employé ;
 *   - `ringcentral_extension_number`: numéro de poste affichable ;
 *   - `ringcentral_phone_numbers`   : tous les numéros assignés à ce poste
 *                                     (json — libellé du sélecteur et CLID) ;
 *   - `ringcentral_synced_at`       : dernière synchro réussie.
 *
 * Nullable : un employé sans compte RingCentral (ou non encore synchronisé)
 * n'a rien de renseigné, la lecture reste possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('ringcentral_extension_id', 64)->nullable()->after('ringcentral_from_number');
            $table->string('ringcentral_extension_number', 32)->nullable()->after('ringcentral_extension_id');
            $table->json('ringcentral_phone_numbers')->nullable()->after('ringcentral_extension_number');
            $table->timestamp('ringcentral_synced_at')->nullable()->after('ringcentral_phone_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'ringcentral_extension_id',
                'ringcentral_extension_number',
                'ringcentral_phone_numbers',
                'ringcentral_synced_at',
            ]);
        });
    }
};
