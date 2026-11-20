<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_items', function (Blueprint $table) {
            // A mind map: {title, children: [{label, refs: [passage ids], children: [...]}]}
            $table->json('tree')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_items', function (Blueprint $table) {
            $table->dropColumn('tree');
        });
    }
};
