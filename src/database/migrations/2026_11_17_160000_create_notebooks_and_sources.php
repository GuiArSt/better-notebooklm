<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notebooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('text'); // text now; pdf, web later
            $table->string('title');
            $table->longText('content');
            $table->string('status')->default('pending'); // pending | ready | failed
            $table->text('error')->nullable();
            $table->boolean('checked')->default(true);
            $table->timestamps();
        });

        // A passage is a slice of one source, with character offsets into the source's content.
        Schema::table('passages', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->after('notebook_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('page')->nullable();
            $table->unsignedInteger('start_offset')->default(0);
            $table->unsignedInteger('end_offset')->default(0);
        });

        // Full-text side of hybrid search. 'simple' keeps names and numbers in any language.
        DB::statement("ALTER TABLE passages ADD COLUMN content_tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple', content)) STORED");
        DB::statement('CREATE INDEX passages_content_tsv_index ON passages USING GIN (content_tsv)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS passages_content_tsv_index');
        Schema::table('passages', function (Blueprint $table) {
            $table->dropColumn('content_tsv');
            $table->dropConstrainedForeignId('source_id');
            $table->dropConstrainedForeignId('notebook_id');
            $table->dropColumn(['position', 'page', 'start_offset', 'end_offset']);
        });
        Schema::dropIfExists('sources');
        Schema::dropIfExists('notebooks');
    }
};
