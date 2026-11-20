<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member')->after('email');
            $table->string('tier')->default('free')->after('role');
            $table->timestamp('pro_until')->nullable()->after('tier');
            $table->string('google_id')->nullable()->unique()->after('pro_until');
            // Google-only users have no password until they set one.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'tier', 'pro_until', 'google_id']);
        });
    }
};
