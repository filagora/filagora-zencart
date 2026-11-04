<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The newsletters and the webhook replay guard, in the shape of zen-cart.com's tables. They point
 * at `users`, so this is dated after the packages' migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('newsletter_subscribers'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained(UserModel::table())->nullOnDelete();
            $table->string('email');
            $table->string('normalized_email')->unique();
            $table->string('suppression_hash', 64)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('source', 64)->default('profile');
            $table->string('mailchimp_id')->nullable()->index();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->string('unsubscribe_reason')->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 64)->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->text('consent_user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['status', 'updated_at']);
        });

        Schema::create(Tables::name('newsletters'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained(UserModel::table())->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained(UserModel::table())->nullOnDelete();
            $table->string('title');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->string('background_color', 7)->default('#F4F1EA');
            $table->string('inner_background_color', 7)->default('#FBF8F2');
            $table->longText('body_html');
            $table->longText('body_text')->nullable();
            $table->json('content_blocks')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create(Tables::name('newsletter_sends'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_id')->constrained(Tables::name('newsletters'))->cascadeOnDelete();
            $table->foreignId('newsletter_subscriber_id')->nullable()->constrained(Tables::name('newsletter_subscribers'))->nullOnDelete();
            $table->string('email');
            $table->string('normalized_email')->index();
            $table->string('name')->nullable();
            $table->string('status', 32)->default('queued');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['newsletter_id', 'newsletter_subscriber_id'], 'newsletter_sends_newsletter_id_newsletter_subscriber_id_unique');
            $table->index(['newsletter_id', 'status']);
        });

        Schema::create(Tables::name('newsletter_events'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_send_id')->constrained(Tables::name('newsletter_sends'))->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamp('occurred_at')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['newsletter_send_id', 'type']);
        });

        Schema::create(Tables::name('newsletter_blocks'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->json('content')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained(UserModel::table())->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained(UserModel::table())->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create(Tables::name('newsletter_link_clicks'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_id')->constrained(Tables::name('newsletters'))->cascadeOnDelete();
            $table->foreignId('newsletter_send_id')->nullable()->constrained(Tables::name('newsletter_sends'))->nullOnDelete();
            $table->foreignId('newsletter_block_id')->nullable()->constrained(Tables::name('newsletter_blocks'))->nullOnDelete();
            $table->string('block_key')->nullable();
            $table->string('label')->nullable();
            $table->text('destination_url');
            $table->timestamp('clicked_at')->index();
            $table->string('ip_hash')->nullable();
            $table->timestamps();

            $table->index('newsletter_block_id');
            $table->index(['newsletter_id', 'block_key']);
        });

        // The shared header and footer every newsletter uses. Added only when missing, so a name or
        // content an editor changed on a live site is left alone.
        foreach (['shared_header' => ['Shared Header', 'header'], 'shared_footer' => ['Shared Footer', 'footer']] as $key => [$name, $type]) {
            if (! DB::table(Tables::name('newsletter_blocks'))->where('key', $key)->exists()) {
                DB::table(Tables::name('newsletter_blocks'))->insert(['key' => $key, 'name' => $name, 'type' => $type, 'content' => null, 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create(Tables::name('webhook_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_key', 191);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('webhook_events'));
        Schema::dropIfExists(Tables::name('newsletter_link_clicks'));
        Schema::dropIfExists(Tables::name('newsletter_blocks'));
        Schema::dropIfExists(Tables::name('newsletter_events'));
        Schema::dropIfExists(Tables::name('newsletter_sends'));
        Schema::dropIfExists(Tables::name('newsletters'));
        Schema::dropIfExists(Tables::name('newsletter_subscribers'));
    }
};
