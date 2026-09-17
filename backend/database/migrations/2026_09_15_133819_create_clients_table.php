<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enterprise_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('categories')->nullable();
            $table->jsonb('rbq_data')->nullable();
            $table->string('status')->default('AVAILABLE');
            $table->foreignUuid('assigned_comercial_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_blacklisted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            // Licence
            $table->string('licence_number')->nullable();
            $table->boolean('licence_propre')->default(false);
            $table->string('intervenant_name')->nullable();
            $table->string('licence_status')->nullable();

            // Identification
            $table->string('neq')->nullable();
            $table->text('full_address')->nullable();
            $table->string('municipality')->nullable();
            $table->string('administrative_region')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Répondants
            $table->integer('respondent_count')->default(0);
            $table->jsonb('respondents')->nullable();

            // Catégories
            $table->integer('sub_category_count')->default(0);
            $table->jsonb('authorized_categories')->nullable();

            // Cautionnement
            $table->string('surety_company')->nullable();
            $table->decimal('surety_amount', 12, 2)->nullable();

            // Dates
            $table->date('licence_start_date')->nullable();
            $table->date('licence_end_date')->nullable();

            // Représentant
            $table->string('representative_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
