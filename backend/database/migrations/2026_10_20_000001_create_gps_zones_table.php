<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones de géorepérage (GPS) — cercles lat/lng/rayon dans lesquels les rôles
 * contraints (`config/geofence.php`) peuvent se connecter. Globales : elles
 * ne dépendent ni de l'entreprise ni d'une source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(500);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_zones');
    }
};
