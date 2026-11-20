<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the money, drop the person (Argus L-1). The ledger's rows used to be deleted with their user, so deleting an account made
 * its spend vanish from the global cap, and a new user could spend it again. Now the row stays and loses only the user id.
 * Forward-only: the first version of these tables cascaded, this one does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['usage_events', 'ledger_reservations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
            });
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->nullable()->change();
            });
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Forward-only on purpose: restoring the cascade would bring the bug back.
    }
};
