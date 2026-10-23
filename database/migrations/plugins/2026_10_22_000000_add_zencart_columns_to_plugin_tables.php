<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns zen-cart.com's plugin tables carry beyond the generic package, placed where the live
 * tables have them. On a plugin: the id the file had in the vBulletin library (the number deployed
 * carts still ask the version check about) and the folder name the plugin installs under. On a
 * release: the folder named after its version inside an encapsulated archive, and whether the
 * archive is encapsulated at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('plugins'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_vbulletin_file_id')->nullable()->unique()->after('id');
            $table->string('zip_root', 180)->nullable()->unique()->after('legacy_vbulletin_file_id');
        });

        Schema::table(Tables::name('plugin_releases'), function (Blueprint $table): void {
            $table->string('version_folder', 40)->nullable()->after('version');
            $table->string('encapsulated', 16)->default('unknown')->after('changelog');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('plugin_releases'), function (Blueprint $table): void {
            $table->dropColumn(['version_folder', 'encapsulated']);
        });

        Schema::table(Tables::name('plugins'), function (Blueprint $table): void {
            $table->dropUnique(['zip_root']);
            $table->dropUnique(['legacy_vbulletin_file_id']);
            $table->dropColumn(['zip_root', 'legacy_vbulletin_file_id']);
        });
    }
};
