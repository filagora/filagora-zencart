<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The roles a person holds by name (the live site has a handful, `super_admin` the one that
 * matters), in the shape of zen-cart.com's tables, with the key and foreign key names written out
 * as the live tables have them, unprefixed. Only the names are used; there are no permission tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('roles'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });

        Schema::create(Tables::name('model_has_roles'), function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id', 'model_has_roles_role_id_foreign')
                ->references('id')->on(Tables::name('roles'))->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('model_has_roles'));
        Schema::dropIfExists(Tables::name('roles'));
    }
};
