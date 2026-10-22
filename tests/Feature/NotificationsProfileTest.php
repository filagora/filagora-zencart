<?php

declare(strict_types=1);

use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Messages\Contracts\MessagingPolicy;
use Filagora\Notifications\Contracts\EmailSuppression;
use Filagora\Notifications\Enums\NotificationChannel as Channel;
use Filagora\Notifications\NotificationCategories;
use Filagora\Notifications\Services\Preferences;
use Filagora\ZenCart\Support\EntitlementEmailSuppression;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

const CAN_RECEIVE_EMAILS = 'notifications.email.enabled';

function member(array $attributes = []): TestUser
{
    $user = TestUser::make();
    if ($attributes !== []) {
        DB::table('users')->where('id', $user->id)->update($attributes);
    }

    return $user->refresh();
}

function grantEmails(TestUser $user): void
{
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => CAN_RECEIVE_EMAILS, 'grant_type' => 'manual']);
}

it('maps the staff ability to see notification choices to the site\'s user editing key', function () {
    $staff = TestUser::make('Staff');
    $other = TestUser::make('Other');
    UserEntitlementGrant::create(['user_id' => $staff->id, 'entitlement_key' => 'operator.users.update', 'grant_type' => 'manual']);

    expect(config('filagora-notifications.gates'))->toBe(['filagora.notifications.view' => 'operator.users.update'])
        ->and(Gate::forUser($staff)->allows('filagora.notifications.view'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('filagora.notifications.view'))->toBeFalse();
});

it('adds the two categories only this site has, after the packages\' own', function () {
    $categories = app(NotificationCategories::class)->all();

    expect(array_slice(array_keys($categories), -2))->toBe(['plugins.release_updates', 'privacy.request_updates'])
        ->and($categories['plugins.release_updates']->channels)->toBe([Channel::EMAIL, Channel::PUSH, Channel::IN_APP])
        ->and($categories['plugins.release_updates']->unsubscribable)->toBeTrue()
        ->and($categories['privacy.request_updates']->channels)->toBe([Channel::EMAIL])
        ->and($categories['privacy.request_updates']->unsubscribable)->toBeFalse();
});

it('only lets a link switch off the three kinds of optional mail the site has always allowed', function () {
    expect(app(NotificationCategories::class)->unsubscribable())->toBe([
        'forum.thread_updates_instant',
        'forum.thread_updates_digest',
        'plugins.release_updates',
    ]);
});

it('binds the site\'s suppression, which also asks for the entitlement to receive email', function () {
    expect(app(EmailSuppression::class))->toBeInstanceOf(EntitlementEmailSuppression::class);
});

it('suppresses optional mail for a member without the entitlement and not for one with it', function () {
    $without = member();
    $with = member();
    grantEmails($with);

    expect(app(EmailSuppression::class)->isSuppressed($without))->toBeTrue()
        ->and(app(EmailSuppression::class)->isSuppressed($with))->toBeFalse()
        ->and(app(Preferences::class)->canDeliver($without, 'forum.thread_updates_instant'))->toBeFalse()
        ->and(app(Preferences::class)->canDeliver($with, 'forum.thread_updates_instant'))->toBeTrue();
});

it('keeps the package\'s own reasons to suppress, even for a member with the entitlement', function (array $attributes) {
    $user = member($attributes);
    grantEmails($user);

    expect(app(EmailSuppression::class)->isSuppressed($user))->toBeTrue();
})->with([
    'mail switched off' => [['receives_email' => false]],
    'bounced address' => [['email_status' => 'bounced']],
    'complaint' => [['email_status' => 'complained']],
]);

it('lets everyone through when the entitlement rule is turned off', function () {
    config(['filagora-zencart.notifications.email_entitlement' => null]);

    expect(app(EmailSuppression::class)->isSuppressed(member()))->toBeFalse();
});

it('does not suppress push or the bell for a member without the entitlement', function () {
    $user = member();

    expect(app(Preferences::class)->canDeliver($user, 'forum.mention', Channel::PUSH))->toBeTrue()
        ->and(app(Preferences::class)->canDeliver($user, 'forum.mention', Channel::IN_APP))->toBeTrue();
});

it('copies the chosen digest frequency into the user column', function () {
    $user = member();

    app(Preferences::class)->setMode($user, 'forum.thread_updates_digest_frequency', 'weekly');

    expect(DB::table('users')->where('id', $user->id)->value('thread_subscription_email_frequency'))->toBe('weekly');

    app(Preferences::class)->setMode($user, 'forum.thread_updates_digest_frequency', 'instant');

    expect(DB::table('users')->where('id', $user->id)->value('thread_subscription_email_frequency'))->toBe('instant');
});

it('copies contactability into the user column, as a boolean', function () {
    $user = member();

    app(Preferences::class)->set($user, 'messages.private_message_contactability', Channel::EMAIL, false);
    expect((bool) DB::table('users')->where('id', $user->id)->value('allow_private_messages'))->toBeFalse();

    app(Preferences::class)->set($user, 'messages.private_message_contactability', Channel::EMAIL, true);
    expect((bool) DB::table('users')->where('id', $user->id)->value('allow_private_messages'))->toBeTrue();
});

it('copies only that person\'s choice, and only those two categories', function () {
    $user = member();
    $other = member();

    app(Preferences::class)->set($user, 'messages.private_message_contactability', Channel::EMAIL, false);
    app(Preferences::class)->set($user, 'forum.mention', Channel::EMAIL, false);
    app(Preferences::class)->set($user, 'messages.received', Channel::PUSH, false);

    expect((bool) DB::table('users')->where('id', $other->id)->value('allow_private_messages'))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('thread_subscription_email_frequency'))->toBe('instant');
});

it('skips a column the user table does not have, and one the profile is told to ignore', function () {
    $user = member();
    config(['filagora-zencart.user_attributes.allow_private_messages' => 'no_such_column']);

    app(Preferences::class)->set($user, 'messages.private_message_contactability', Channel::EMAIL, false);

    config(['filagora-zencart.user_attributes.thread_subscription_email_frequency' => '']);
    app(Preferences::class)->setMode($user, 'forum.thread_updates_digest_frequency', 'daily');

    expect((bool) DB::table('users')->where('id', $user->id)->value('allow_private_messages'))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('thread_subscription_email_frequency'))->toBe('instant');
});

it('refuses to let someone message a member who switched messages off, and reads the column until they choose', function () {
    $sender = member();
    $closed = member(['allow_private_messages' => false]);

    expect(app(MessagingPolicy::class)->canMessage($sender, $closed))->toBeFalse();

    app(Preferences::class)->set($closed, 'messages.private_message_contactability', Channel::EMAIL, true);

    expect(app(MessagingPolicy::class)->canMessage($sender, $closed))->toBeTrue();
});

it('declares the module as requiring the notifications package', function () {
    $module = app(ModuleRegistry::class)->all()['zencart'];

    expect($module->requires)->toContain('notifications');
});
