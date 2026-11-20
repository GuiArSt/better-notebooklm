<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The language of the source's own text (en, fr, de, es, it, pt, nl), detected in code when it is loaded; null when it could not be told.
        Schema::table('sources', fn (Blueprint $table) => $table->string('language', 8)->nullable());
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('language'));
    }
};
