<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            // Jeton OAuth RingCentral **propre à l'entreprise** : le premier
            // échange JWT → access_token est persisté ici, puis réutilisé par
            // les requêtes suivantes (refresh inclus). Tableau brut du SDK
            // (`access_token`, `refresh_token`, `expire_time`, …).
            $table->json('ringcentral_auth')->nullable()->after('ringcentral_token');
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn('ringcentral_auth');
        });
    }
};
