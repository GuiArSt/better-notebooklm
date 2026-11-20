<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('origin_url')->nullable();
            $table->string('file_path')->nullable();
            $table->text('summary')->nullable();
            // PDF: the character range of each page inside `content`, [{page, start, end}].
            $table->json('pages')->nullable();
            $table->longText('content')->nullable()->change();
        });

        // The notebook's opening summary and suggested questions, rebuilt when its sources change.
        Schema::create('notebook_overviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary');
            $table->json('questions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_overviews');
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['origin_url', 'file_path', 'summary', 'pages']);
        });
    }
};
