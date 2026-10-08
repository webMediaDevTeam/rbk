<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Répertoire fermé de « sources » — **lecture seule** : la seule route
     * exposée est `GET /api/v1/sources` (aucun CRUD), pour alimenter le
     * sélecteur « Source » des modales « Créer / Modifier une entreprise ».
     *
     * Lignes initiales insérées par `Database\Seeders\SourceSeeder`
     * (`php artisan db:seed --class=SourceSeeder`, idempotent).
     *
     * Aucune donnée supprimée : `php artisan migrate` uniquement.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
