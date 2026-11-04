<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which old forum member each user was imported from, in the shape of zen-cart.com's table, with
 * the key and foreign key names written out as the live table has them, unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('legacy_import_user_maps'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_user_id');
            $table->unsignedBigInteger('user_id');
            $table->string('legacy_username')->nullable();
            $table->string('legacy_email')->nullable();
            $table->unsignedInteger('legacy_primary_group_id')->nullable();
            $table->string('legacy_member_group_ids')->nullable();
            $table->boolean('legacy_is_banned')->default(false);
            $table->text('legacy_ban_reason')->nullable();
            $table->timestamp('legacy_banned_until')->nullable();
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();

            $table->unique('legacy_user_id', 'legacy_import_user_maps_legacy_user_id_unique');
            $table->index('user_id', 'legacy_import_user_maps_user_id_foreign');

            $table->foreign('user_id', 'legacy_import_user_maps_user_id_foreign')
                ->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('legacy_import_user_maps'));
    }
};
