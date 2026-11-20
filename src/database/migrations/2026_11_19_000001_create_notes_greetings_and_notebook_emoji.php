<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('emoji', 16)->default('📓');
            $table->string('featured_slug')->nullable(); // set on the copy a user made of a featured notebook
        });

        // A note is the user's own writing, or a pinned chat answer. Checked, it becomes a source (kind 'note').
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('');
            $table->text('content')->default('');
            $table->json('citations')->nullable(); // the resolved citations of a pinned answer
            $table->boolean('from_chat')->default(false);
            $table->boolean('checked')->default(false);
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // The greeting (D-006) is shown once per user: this row says they have read it.
        Schema::create('greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greetings');
        Schema::dropIfExists('notes');
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn(['emoji', 'featured_slug']);
        });
    }
};
