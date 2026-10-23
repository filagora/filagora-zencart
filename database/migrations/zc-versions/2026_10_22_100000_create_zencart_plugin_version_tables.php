<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Zen Cart versions a release works with, in the shape of zen-cart.com's tables: the list of
 * versions (with whether each is offered and in what order), and which releases work with which.
 * The key and foreign key names are written out as the live tables have them, unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('plugin_zc_versions'), function (Blueprint $table): void {
            $table->id();
            $table->string('version', 40);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique('version', 'plugin_zc_versions_version_unique');
            $table->index('is_active', 'plugin_zc_versions_is_active_index');
            $table->index(['is_active', 'position', 'version'], 'plugin_zc_versions_active_position_version_idx');
        });

        Schema::create(Tables::name('plugin_release_zc_versions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plugin_release_id');
            $table->unsignedBigInteger('plugin_zc_version_id');
            $table->timestamps();

            $table->unique(['plugin_release_id', 'plugin_zc_version_id'], 'plugin_release_zc_unique');
            $table->index('plugin_zc_version_id', 'plugin_release_zc_versions_plugin_zc_version_id_foreign');

            $table->foreign('plugin_release_id', 'plugin_release_zc_versions_plugin_release_id_foreign')
                ->references('id')->on(Tables::name('plugin_releases'))->cascadeOnDelete();
            $table->foreign('plugin_zc_version_id', 'plugin_release_zc_versions_plugin_zc_version_id_foreign')
                ->references('id')->on(Tables::name('plugin_zc_versions'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('plugin_release_zc_versions'));
        Schema::dropIfExists(Tables::name('plugin_zc_versions'));
    }
};
