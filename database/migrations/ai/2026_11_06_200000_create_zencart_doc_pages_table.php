<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pages of the Zen Cart documentation, imported from its repository so the AI chat can answer
 * from them. Key names are written out, unprefixed, as sites that already have the table have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('doc_pages'), function (Blueprint $table): void {
            $table->id();
            $table->string('source_file', 500);
            $table->string('url_path', 500);
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            $table->string('category', 120)->nullable();
            $table->longText('body');
            $table->timestamps();

            $table->unique('source_file', 'doc_pages_source_file_unique');
            $table->unique('url_path', 'doc_pages_url_path_unique');
            $table->index('category', 'doc_pages_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('doc_pages'));
    }
};
