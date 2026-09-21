<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            if (Schema::hasColumn('enterprises', 'user_id')) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Throwable $e) {
                }
                $table->dropColumn('user_id');
            }

            if (! Schema::hasColumn('enterprises', 'email')) {
                $table->string('email')->nullable()->after('name');
            }

            if (! Schema::hasColumn('enterprises', 'status')) {
                $table->string('status')->default('ACTIVE')->after('logo');
            }

            if (! Schema::hasColumn('enterprises', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            if (Schema::hasColumn('enterprises', 'email')) {
                $table->dropColumn('email');
            }
            if (Schema::hasColumn('enterprises', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('enterprises', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
            if (! Schema::hasColumn('enterprises', 'user_id')) {
                $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            }
        });
    }
};
