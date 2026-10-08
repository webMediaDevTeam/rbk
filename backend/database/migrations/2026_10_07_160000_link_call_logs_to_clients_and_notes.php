<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignUuid('client_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
        });

        Schema::table('notes', function (Blueprint $table) {
            $table->foreignUuid('call_log_id')->nullable()->after('client_id')->constrained('call_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_log_id');
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
