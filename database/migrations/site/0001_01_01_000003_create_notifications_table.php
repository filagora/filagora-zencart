<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel's database notifications (the header bell), in the shape of zen-cart.com's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('notifications'), function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_type_notifiable_id_read_at_index');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_idx');
            $table->index('created_at', 'notifications_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('notifications'));
    }
};
