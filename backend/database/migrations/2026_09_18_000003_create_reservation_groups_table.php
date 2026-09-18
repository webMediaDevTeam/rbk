<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('comercial_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->integer('total')->default(0);
            $table->integer('reserved_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_groups');
    }
};