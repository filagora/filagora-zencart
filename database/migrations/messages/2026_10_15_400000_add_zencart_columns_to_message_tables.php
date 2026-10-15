<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns zen-cart.com's private message tables carry beyond the generic package: the ids of the
 * vBulletin message and message text each row was imported from. Placed where the live tables have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('private_conversations'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_root_pmid')->nullable()->unique()->after('id');
        });

        Schema::table(Tables::name('private_messages'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_pmtext_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('private_messages'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_pmtext_id']);
            $table->dropColumn('legacy_pmtext_id');
        });

        Schema::table(Tables::name('private_conversations'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_root_pmid']);
            $table->dropColumn('legacy_root_pmid');
        });
    }
};
