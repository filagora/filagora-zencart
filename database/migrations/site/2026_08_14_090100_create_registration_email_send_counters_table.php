<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How many registration emails went out each day, for the site's daily cap. In the shape of
 * zen-cart.com's table, with the key name written out as the live table has it, unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('registration_email_send_counters'), function (Blueprint $table): void {
            $table->id();
            $table->date('send_date');
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();

            $table->unique('send_date', 'registration_email_send_counters_send_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('registration_email_send_counters'));
    }
};
