<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The columns the site's user table carries for email and messages, which the profile reads and
 * never adds: they are the host's. Only the test database gets them here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('receives_email')->default(true);
            $table->string('email_status', 32)->nullable();
            $table->string('thread_subscription_email_frequency', 16)->default('instant');
            $table->boolean('allow_private_messages')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['receives_email', 'email_status', 'thread_subscription_email_frequency', 'allow_private_messages']);
        });
    }
};
