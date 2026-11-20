<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which studio item a hold was made for (D-038): when a worker is killed mid-job, the hold is released with the item, not charged in full later.
        // Null for any hold that belongs to no item (a chat answer, a source), which behave as before.
        Schema::table('ledger_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('studio_item_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_reservations', fn (Blueprint $table) => $table->dropColumn('studio_item_id'));
    }
};
