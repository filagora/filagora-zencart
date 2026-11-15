<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The webhook replay guard, in the shape of zen-cart.com's table: one row per provider event
 * already handled, kept until it expires. (The newsletter tables that used to be created beside
 * it belong to `filagora/filagora-newsletters` now.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('webhook_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_key', 191);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('webhook_events'));
    }
};
