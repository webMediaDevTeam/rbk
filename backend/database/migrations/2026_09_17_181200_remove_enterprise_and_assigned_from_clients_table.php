<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'assigned_comercial_id')) {
                // drop foreign if exists
                try {
                    $table->dropForeign(['assigned_comercial_id']);
                } catch (\Throwable $e) {
                    // ignore if constraint missing
                }
                $table->dropColumn('assigned_comercial_id');
            }

            if (Schema::hasColumn('clients', 'enterprise_id')) {
                try {
                    $table->dropForeign(['enterprise_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('enterprise_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'enterprise_id')) {
                $table->foreignUuid('enterprise_id')->nullable()->constrained()->nullOnDelete()->after('id');
            }

            if (! Schema::hasColumn('clients', 'assigned_comercial_id')) {
                $table->foreignUuid('assigned_comercial_id')->nullable()->constrained('users')->nullOnDelete()->after('assigned_comercial_id');
            }
        });
    }
};
