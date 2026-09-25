<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La table clients n'avait que `created_at` : le modèle désactivait le
     * timestamps Eloquent (`UPDATED_AT = null`). On ajoute `updated_at`,
     * renseigné automatiquement à chaque modification d'un client.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // Rétrofit des lignes existantes : created_at plutôt que NULL.
        DB::table('clients')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
