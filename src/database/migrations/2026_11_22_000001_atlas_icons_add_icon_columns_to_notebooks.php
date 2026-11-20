<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            // null = no icon was asked for (switch off, no key, or an old notebook); queued -> running -> done | failed.
            $table->string('icon_status', 16)->nullable()->index();
            $table->string('icon_path')->nullable();      // on the private disk: icons/<user id>/<notebook id>.webp
            $table->string('icon_sha256', 64)->nullable();
            $table->unsignedSmallInteger('icon_width')->nullable();
            $table->unsignedSmallInteger('icon_height')->nullable();
            $table->string('icon_error', 64)->nullable(); // the error CLASS only, never a message, never the title
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['icon_status', 'icon_path', 'icon_sha256', 'icon_width', 'icon_height', 'icon_error']);
        });
    }
};
