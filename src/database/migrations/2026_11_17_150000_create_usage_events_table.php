<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: one row per model call, written when the call settles.
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('notebook_id')->nullable()->index();
            $table->string('provider');
            $table->string('model');
            $table->string('kind'); // chat | embed | ocr | stt | tts | video | image
            $table->json('units');  // tokens in/out, characters, pages, seconds
            $table->unsignedBigInteger('cost_micro_usd');
            $table->string('key_owner')->default('app'); // app | user
            $table->string('prompt_name')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('langfuse_trace_id')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Money held for a call in flight: reserve before the call, settle (delete) after.
        Schema::create('ledger_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_micro_usd');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reservations');
        Schema::dropIfExists('usage_events');
    }
};
