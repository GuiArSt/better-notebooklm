<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_items', function (Blueprint $table) {
            $table->json('settings')->nullable(); // how the item was made; null for items made before this column
        });
        Schema::table('usage_events', function (Blueprint $table) {
            $table->unsignedBigInteger('studio_item_id')->nullable()->index(); // which item a model call was made for (no foreign key: the ledger outlives the item)
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', fn (Blueprint $table) => $table->dropColumn('studio_item_id'));
        Schema::table('studio_items', fn (Blueprint $table) => $table->dropColumn('settings'));
    }
};
