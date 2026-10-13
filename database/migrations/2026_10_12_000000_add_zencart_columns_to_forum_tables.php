<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns zen-cart.com's forum tables carry beyond the generic package: legacy import ids
 * and the plugin-support flag. Placed where the live tables have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('forums'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_vbulletin_forum_id')->nullable()->unique()->after('id');
            $table->boolean('is_plugin_support_forum')->default(false)->after('show_when_empty');
        });

        Schema::table(Tables::name('threads'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_vbulletin_thread_id')->nullable()->unique()->after('id');
        });

        Schema::table(Tables::name('posts'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_post_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('posts'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_post_id']);
            $table->dropColumn('legacy_post_id');
        });

        Schema::table(Tables::name('threads'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_vbulletin_thread_id']);
            $table->dropColumn('legacy_vbulletin_thread_id');
        });

        Schema::table(Tables::name('forums'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_vbulletin_forum_id']);
            $table->dropColumn(['legacy_vbulletin_forum_id', 'is_plugin_support_forum']);
        });
    }
};
