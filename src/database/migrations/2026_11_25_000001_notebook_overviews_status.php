<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The overview gets a state (D-038): building, ready or failed. A row may now exist before there is any text (it says "building"), so the
        // text columns can be empty. Rows from before keep status null, which the app reads as ready.
        Schema::table('notebook_overviews', function (Blueprint $table) {
            $table->string('status', 16)->nullable();
            $table->text('summary')->nullable()->change();
            $table->json('questions')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rollback-safe: a row with no text exists only because it said "building" or "failed": drop those first, then restore the old shape.
        DB::table('notebook_overviews')->whereNull('summary')->orWhereNull('questions')->delete();

        Schema::table('notebook_overviews', function (Blueprint $table) {
            $table->text('summary')->nullable(false)->change();
            $table->json('questions')->nullable(false)->change();
            $table->dropColumn('status');
        });
    }
};
