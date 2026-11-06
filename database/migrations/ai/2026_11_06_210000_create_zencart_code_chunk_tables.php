<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Zen Cart source behind the AI chat's code knowledge. One row per unique piece of code: the
 * same function in two releases is one row, embedded once, that lists the versions holding it.
 * Key names are written out, unprefixed, as sites that already have the tables have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('code_chunks'), function (Blueprint $table): void {
            $table->id();
            $table->char('content_hash', 64);
            $table->string('repo', 32);
            $table->string('kind', 16);
            $table->string('symbol')->nullable();
            $table->mediumText('body');
            $table->json('versions');
            $table->json('generations');
            // Null until the chunk has been embedded and sent to the index.
            $table->timestamp('indexed_at')->nullable();
            // Indexed already, but the versions it lists have changed since.
            $table->boolean('versions_dirty')->default(false);
            $table->timestamps();

            $table->unique('content_hash', 'code_chunks_content_hash_unique');
            $table->index(['repo', 'indexed_at'], 'code_chunks_repo_indexed_at_index');
        });

        // Where a chunk sits in each version: the path and lines differ between releases even
        // when the code does not.
        Schema::create(Tables::name('code_chunk_locations'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('code_chunk_id');
            $table->string('version', 32);
            $table->string('path', 400);
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');

            $table->unique(['code_chunk_id', 'version', 'path', 'start_line'], 'code_chunk_locations_unique');
            $table->index('version', 'code_chunk_locations_version_index');
            $table->foreign('code_chunk_id', 'code_chunk_locations_code_chunk_id_foreign')
                ->references('id')->on(Tables::name('code_chunks'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('code_chunk_locations'));
        Schema::dropIfExists(Tables::name('code_chunks'));
    }
};
