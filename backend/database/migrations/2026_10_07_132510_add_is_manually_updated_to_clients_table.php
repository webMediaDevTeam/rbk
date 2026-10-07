<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Drapeau « fiche modifiée à la main » : dès qu'un humain édite
            // un détail du client depuis l'interface (PATCH
            // commercials/clients/{id}/phone), la fiche n'est **plus jamais
            // réécrite** par l'import scraper / n8n —
            // `ClientImportService::upsertFromScraperPayload()` renvoie
            // alors `skipped_manual` (docs/RULES.md §12, spec
            // docs/public_api.md).
            $table->boolean('is_manually_updated')->default(false)->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_manually_updated');
        });
    }
};
