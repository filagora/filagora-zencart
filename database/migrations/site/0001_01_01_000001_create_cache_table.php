<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel's cache tables, in the shape of zen-cart.com's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('cache'), function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create(Tables::name('cache_locks'), function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('cache_locks'));
        Schema::dropIfExists(Tables::name('cache'));
    }
};
