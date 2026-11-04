<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site's users, sessions and password reset tokens, in the shape of zen-cart.com's tables.
 * The Zen Cart site runs with an empty table prefix, so these are the tables Laravel looks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('users'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('membership_expires_at')->nullable();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('time_format', 3)->default('12h');
            $table->text('signature')->nullable();
            $table->string('signature_format', 20)->nullable();
            $table->text('biography')->nullable();
            $table->string('location')->nullable();
            $table->text('interests')->nullable();
            $table->string('occupation')->nullable();
            $table->string('user_title')->nullable();
            $table->boolean('is_banned')->default(false)->index();
            $table->text('ban_reason')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('banned_until')->nullable();
            $table->string('thread_subscription_email_frequency', 16)->default('instant')->index();
            $table->boolean('allow_private_messages')->default(true);
            $table->boolean('always_moderate_forum_content')->default(false);
            $table->boolean('moderate_first_forum_post')->default(false);
            $table->boolean('search_icon_enabled')->default(false);
            $table->boolean('show_archived_plugin_releases')->default(false);
            $table->boolean('prefers_rich_editor')->default(true);
            $table->boolean('auto_subscribe_on_post')->default(false);
            $table->string('password');
            $table->string('legacy_password_hash')->nullable();
            $table->string('legacy_password_salt')->nullable();
            $table->string('legacy_password_algo')->nullable();
            $table->timestamp('legacy_password_imported_at')->nullable();
            $table->dateTime('legacy_registered_at')->nullable()->index();
            $table->string('avatar_path')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_pending_secret')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->string('email_status')->nullable()->index();
            $table->timestamp('email_bounced_at')->nullable();
            $table->timestamp('email_complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('email_last_delivered_at')->nullable();
            $table->boolean('receives_email')->default(true);
            $table->boolean('notify_threads')->default(true);
            $table->boolean('notify_messages')->default(true);
            $table->boolean('notify_marketing')->default(true);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('forum_read_baseline_at')->nullable();
            $table->unsignedInteger('forum_posts_count')->default(0);
        });

        Schema::create(Tables::name('password_reset_tokens'), function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create(Tables::name('sessions'), function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('sessions'));
        Schema::dropIfExists(Tables::name('password_reset_tokens'));
        Schema::dropIfExists(Tables::name('users'));
    }
};
