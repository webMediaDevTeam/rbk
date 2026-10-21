<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préférences applicatives stockées en base (clé → valeur), gérées depuis
 * l'interface d'administration — par opposition à `config/*.php`, figé par
 * l'environnement et le cache de configuration.
 *
 * Usage actuel : le coupe-circuit global du géorepérage GPS
 * (`geofence.enabled`, interrupteur de la page « GPS »), que l'administrateur
 * doit pouvoir basculer sans redéploiement. Une ligne par clé, valeur texte
 * (`'1'` / `'0'` pour les booléens, cf. `App\Models\Setting`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
