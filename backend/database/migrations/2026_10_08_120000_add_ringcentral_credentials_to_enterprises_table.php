<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifiants RingCentral **par entreprise** — chaque société peut
     * brancher son propre compte (application OAuth + jeton) au lieu du
     * compte unique de `.env`.
     *
     *   - `ringcentral_client_id`     : `RINGCENTRAL_CLIENT_ID` de l'entreprise ;
     *   - `ringcentral_client_secret` : secret de cette application ;
     *   - `ringcentral_token`         : jeton (JWT) d'authentification ;
     *   - `source`                    : **sans rapport avec RingCentral** —
     *     libellé choisi dans le répertoire `sources` (table sans CRUD,
     *     lue par `GET /api/v1/sources`).
     *
     * NULL = « pas de compte d'entreprise » : `Enterprise::
     * getRingCentralCredentials()` retombe alors sur `services.ringcentral.*`
     * (`.env`) — comportement strictement inchangé pour les existants.
     *
     * Aucune donnée supprimée : `php artisan migrate` uniquement.
     */
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->string('ringcentral_client_id', 255)->nullable()->after('logo');
            $table->string('ringcentral_client_secret', 500)->nullable()->after('ringcentral_client_id');
            $table->text('ringcentral_token')->nullable()->after('ringcentral_client_secret');
            $table->string('source', 255)->nullable()->after('ringcentral_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn([
                'ringcentral_client_id',
                'ringcentral_client_secret',
                'ringcentral_token',
                'source',
            ]);
        });
    }
};
