<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Note personnalisée du client — texte **libre multi-lignes** saisi dans
     * la modale « Modifier le client » (tous les rôles qui ouvrent cette
     * modale). Chaque retour à la ligne forme un élément de liste à
     * l'affichage ; rien n'est structuré en base, la colonne ne porte
     * qu'un `TEXT` nullable.
     *
     * Aucune suppression de données : `php artisan migrate` uniquement.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('note_personnalise')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('note_personnalise');
        });
    }
};
