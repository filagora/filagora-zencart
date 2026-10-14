<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column zen-cart.com's `cms_pages` table carries beyond the generic package: the id of the
 * vBulletin content item the page was imported from. Placed where the live table has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('cms_pages'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_vbulletin_content_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('cms_pages'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_vbulletin_content_id']);
            $table->dropColumn('legacy_vbulletin_content_id');
        });
    }
};
