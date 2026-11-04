<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sanctum's API keys, in the shape of zen-cart.com's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('personal_access_tokens'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable', 'personal_access_tokens_tokenable_type_tokenable_id_index');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('personal_access_tokens'));
    }
};
