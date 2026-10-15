<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column zen-cart.com's `reactions` table carries beyond the generic package: the id of the
 * vBulletin reaction it was imported from. Placed where the live table has it. A separate
 * migration, dated after the forums package's reactions migration, which has to run first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::name('reactions'), function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_reaction_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::name('reactions'), function (Blueprint $table): void {
            $table->dropUnique(['legacy_reaction_id']);
            $table->dropColumn('legacy_reaction_id');
        });
    }
};
