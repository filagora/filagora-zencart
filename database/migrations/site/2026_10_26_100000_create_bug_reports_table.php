<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bug reports raised on forum threads, in the shape of zen-cart.com's table, with the key and
 * foreign key names written out as the live table has them, unprefixed. Dated after the forums
 * package's migrations because it points at their threads table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('bug_reports'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('reported_by_id');
            $table->unsignedBigInteger('assigned_to_id')->nullable();
            $table->string('status', 16)->default('reported');
            $table->text('description')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status', 'bug_reports_status_index');
            $table->index(['thread_id', 'status'], 'bug_reports_thread_id_status_index');
            $table->index(['assigned_to_id', 'status'], 'bug_reports_assigned_to_id_status_index');
            $table->index('reported_by_id', 'bug_reports_reported_by_id_foreign');

            $table->foreign('thread_id', 'bug_reports_thread_id_foreign')
                ->references('id')->on(Tables::name('threads'))->cascadeOnDelete();
            $table->foreign('reported_by_id', 'bug_reports_reported_by_id_foreign')
                ->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('assigned_to_id', 'bug_reports_assigned_to_id_foreign')
                ->references('id')->on(UserModel::table())->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('bug_reports'));
    }
};
