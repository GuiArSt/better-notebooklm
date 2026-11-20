<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One generated thing in the notebook's Studio: an audio overview or a report. Shape of a job:
        // pending -> running (step n of m) -> done | failed.
        Schema::create('studio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // audio | briefing | study_guide | faq
            $table->string('title');
            $table->string('status')->default('pending');
            $table->string('step')->nullable();
            $table->unsignedSmallInteger('step_n')->default(0);
            $table->unsignedSmallInteger('step_total')->default(0);
            $table->longText('content')->nullable();   // a report's markdown, with [P12] markers
            $table->json('citations')->nullable();     // the markers resolved: passage, source, page, offsets, quote
            $table->json('script')->nullable();        // an audio overview's script: [{speaker, text}]
            $table->string('file_path')->nullable();   // the MP3
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_items');
    }
};
