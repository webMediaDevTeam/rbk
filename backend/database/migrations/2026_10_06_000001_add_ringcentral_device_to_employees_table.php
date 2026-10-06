<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source d'appel RingCentral de l'employé (COMERCIAL) — choisi dans les
 * modales « Créer / Modifier un employé » (Admin / Super Admin) :
 *
 *   - `ringcentral_device_id`  : appareil sélectionné (id RingCentral) ;
 *   - `ringcentral_from_number`: numéro affiché dans la sélection (numéro
 *     assigné à l'extension de l'appareil), figé au moment du choix pour
 *     rester affichable sans rappeler l'API.
 *
 * Nullable : les employés existants et ceux sans appareil n'ont aucune
 * source renseignée (la console d'appel retombe sur son résolution auto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('ringcentral_device_id', 64)->nullable()->after('additional_info');
            $table->string('ringcentral_from_number', 32)->nullable()->after('ringcentral_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['ringcentral_device_id', 'ringcentral_from_number']);
        });
    }
};
