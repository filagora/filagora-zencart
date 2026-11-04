<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The members each member keeps as contacts, in the shape of zen-cart.com's table, with the key
 * and foreign key names written out as the live table has them, unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('contacts'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_user_id');
            $table->timestamps();

            $table->unique(['user_id', 'contact_user_id'], 'contacts_user_id_contact_user_id_unique');
            $table->index(['user_id', 'created_at'], 'contacts_user_id_created_at_index');
            $table->index('contact_user_id', 'contacts_contact_user_id_foreign');

            $table->foreign('user_id', 'contacts_user_id_foreign')
                ->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('contact_user_id', 'contacts_contact_user_id_foreign')
                ->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('contacts'));
    }
};
