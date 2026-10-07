<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colonnes **dérivées** de recherche, tenues à jour par
     * `App\Observers\ClientObserver` :
     *
     *  - `phone_normalized` : clé de chiffres nationaux (`8194186550`),
     *    insensible au formatage saisi ;
     *  - `simple_name` : nom sans accent ni casse (`jose tremblay`).
     *
     * Les deux sont indexées : elles servent de clé de recherche exacte
     * (les index ne servent que si la colonne est peuplée — d'où le
     * remplissage des lignes existantes ci-dessous).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('phone_normalized', 16)->nullable()->after('phone');
            $table->string('simple_name')->nullable()->after('name');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index('phone_normalized');
            $table->index('simple_name');
        });

        // Remplissage des lignes existantes : sans cet UPDATE, tout
        // l'historique resterait NULL et un futur filtre l'ignorerait
        // silencieusement. Logique **empruntée** aux helpers du modèle
        // (`Client::normalizePhone()` / `Client::simpleName()`) pour ne
        // jamais diverger de la valeur que l'observateur écrit ensuite.
        DB::table('clients')
            ->select(['id', 'phone', 'name'])
            ->where(fn ($query) => $query->whereNull('phone_normalized')->orWhereNull('simple_name'))
            ->chunkById(200, function ($clients) {
                foreach ($clients as $client) {
                    DB::table('clients')->where('id', $client->id)->update([
                        'phone_normalized' => Client::normalizePhone($client->phone),
                        'simple_name' => Client::simpleName($client->name),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['phone_normalized']);
            $table->dropIndex(['simple_name']);
            $table->dropColumn(['phone_normalized', 'simple_name']);
        });
    }
};
