<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->change();
            $table->timestamp('email_verified_at')->nullable()->after('password_hash');
            $table->string('verification_token', 128)->nullable()->unique()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['verification_token']);
            $table->dropColumn(['email_verified_at', 'verification_token']);
            $table->string('password_hash')->nullable(false)->change();
        });
    }
};
