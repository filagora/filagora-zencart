<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The queue jobs screen's record of each job, in the shape of zen-cart.com's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('queue_monitors'), function (Blueprint $table): void {
            $table->id();
            $table->string('job_id')->index();
            $table->string('name')->nullable();
            $table->string('queue')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('failed')->default(false)->index();
            $table->integer('attempt')->default(0);
            $table->integer('progress')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->text('exception_message')->nullable();
            $table->string('exception_class')->nullable()->index();
            $table->text('exception')->nullable();
            $table->string('failure_signature', 64)->nullable()->index();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('queue_monitors'));
    }
};
