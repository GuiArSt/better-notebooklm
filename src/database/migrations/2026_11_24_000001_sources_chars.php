<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The length of the source's text in characters (D-040): what the "large source" warning and its cost estimate are read from,
        // so a list of sources never has to load every source's whole text.
        Schema::table('sources', fn (Blueprint $table) => $table->unsignedInteger('chars')->nullable());
        DB::statement('UPDATE sources SET chars = char_length(content) WHERE content IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('chars'));
    }
};
