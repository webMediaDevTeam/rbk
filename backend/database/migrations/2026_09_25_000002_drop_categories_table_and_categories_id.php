<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suppression de la table `categories` (plus lue : les filtres utilisent
     * les valeurs distinctes de `clients.categories`) et de la colonne
     * `clients.categories_id`, qui n'avait plus de sens sans la table.
     */
    public function up(): void
    {
        if (Schema::hasTable('categories')) {
            Schema::dropIfExists('categories');
        }

        if (Schema::hasColumn('clients', 'categories_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('categories_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('label')->nullable();
                $table->text('description')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasColumn('clients', 'categories_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->json('categories_id')->nullable()->after('categories');
            });
        }
    }
};
