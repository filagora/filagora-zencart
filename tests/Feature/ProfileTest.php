<?php

declare(strict_types=1);

use ForumKit\Entitlements\Contracts\SuperUserCheck;
use ForumKit\Entitlements\Models\EntitlementDefinition;
use ForumKit\Entitlements\Models\UserEntitlementGrant;
use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Contracts\PaidAccessCheck;
use ForumKit\Forums\Contracts\PostSignatureResolver;
use ForumKit\Forums\Enums\ContentModerationStatus;
use ForumKit\Forums\Enums\ForumVisibility;
use ForumKit\Forums\Exceptions\PostingNotPermitted;
use ForumKit\Forums\Models\ForumGroup;
use ForumKit\Forums\Services\ApprovalRules;
use ForumKit\Forums\Services\ForumAccess;
use ForumKit\Forums\Services\Posting;
use ForumKit\Forums\Services\ReplyCreation;
use ForumKit\Forums\Services\ThreadCreation;
use ForumKit\Forums\Support\Models;
use ForumKit\ZenCart\Models\Forum;
use ForumKit\ZenCart\Models\Post;
use ForumKit\ZenCart\Models\Thread;
use ForumKit\ZenCart\Seeders\OperatorEntitlementSeeder;
use ForumKit\ZenCart\Support\MembershipPaidAccess;
use ForumKit\ZenCart\Support\RoleSuperUserCheck;
use ForumKit\ZenCart\Support\UserFlagsModerationPolicy;
use ForumKit\ZenCart\Support\UserSignatureResolver;
use ForumKit\ZenCart\Tests\Support\TestUser;
use ForumKit\ZenCart\ZenCartServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

function makeForum(array $attributes = []): Forum
{
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);

    return Forum::create(array_merge(['forum_group_id' => $group->id, 'name' => 'F'.uniqid(), 'slug' => 'f-'.uniqid()], $attributes))->refresh();
}

function freshServices(): void
{
    foreach ([ForumAccess::class, ApprovalRules::class, ThreadCreation::class, ReplyCreation::class, Posting::class] as $service) {
        app()->forgetInstance($service);
    }
}

// ------------------------------------------------------------------ models

it('uses the Zen Cart models for forums, threads and posts', function () {
    expect(Models::class('forum'))->toBe(Forum::class)
        ->and(Models::class('thread'))->toBe(Thread::class)
        ->and(Models::class('post'))->toBe(Post::class);
});

it('keeps a model the host already chose', function () {
    expect(config('forumkit.models.forum'))->toBe(Forum::class);

    $this->app['config']->set('forumkit.models.forum', 'Host\\Forum');
    (new ReflectionMethod(ZenCartServiceProvider::class, 'useZenCartModels'))
        ->invoke(new ZenCartServiceProvider($this->app));

    expect(config('forumkit.models.forum'))->toBe('Host\\Forum');
});

it('stores and casts the extra columns, and relations return the subclasses', function () {
    $author = TestUser::make('Author');
    $forum = makeForum(['legacy_vbulletin_forum_id' => '12', 'is_plugin_support_forum' => true]);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't', 'legacy_vbulletin_thread_id' => '34'])->refresh();
    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'b', 'content_format' => 'markdown', 'legacy_post_id' => '56'])->refresh();

    expect($forum->legacy_vbulletin_forum_id)->toBe(12)
        ->and($forum->is_plugin_support_forum)->toBeTrue()
        ->and($thread->legacy_vbulletin_thread_id)->toBe(34)
        ->and($post->legacy_post_id)->toBe(56)
        ->and($thread->forum)->toBeInstanceOf(Forum::class)
        ->and($post->thread)->toBeInstanceOf(Thread::class)
        ->and($forum->threads->first())->toBeInstanceOf(Thread::class);
});

it('keeps the package\'s own fillable fields and casts', function () {
    $forum = new Forum(['name' => 'X', 'visibility' => 'public']);

    expect($forum->name)->toBe('X')
        ->and($forum->visibility)->toBe(ForumVisibility::PUBLIC);
});

it('does not let two forums share a legacy id', function () {
    makeForum(['legacy_vbulletin_forum_id' => 5]);

    expect(fn () => makeForum(['legacy_vbulletin_forum_id' => 5]))->toThrow(QueryException::class);
});

// ------------------------------------------------------------------ bindings

it('binds the Zen Cart implementations of the contracts', function () {
    expect(app(SuperUserCheck::class))->toBeInstanceOf(RoleSuperUserCheck::class)
        ->and(app(PaidAccessCheck::class))->toBeInstanceOf(MembershipPaidAccess::class)
        ->and(app(ContentModerationPolicy::class))->toBeInstanceOf(UserFlagsModerationPolicy::class)
        ->and(app(PostSignatureResolver::class))->toBeInstanceOf(UserSignatureResolver::class);
});

it('treats the super_admin role as holding everything', function () {
    $admin = TestUser::make('Admin');
    $admin->roles = ['super_admin'];
    $other = TestUser::make('Other');
    $other->roles = ['forum_moderator'];

    $check = new RoleSuperUserCheck;

    expect($check->bypasses($admin))->toBeTrue()
        ->and($check->bypasses($other))->toBeFalse();

    config(['forumkit-zencart.super_admin_role' => 'root']);
    expect($check->bypasses($admin))->toBeFalse();

    config(['forumkit-zencart.super_admin_role' => '']);
    expect($check->bypasses($admin))->toBeFalse();
});

it('has no super users on a model without roles', function () {
    $plain = new class extends Model {};

    expect((new RoleSuperUserCheck)->bypasses($plain))->toBeFalse();
});

it('lets a super admin through the entitlement gates with no grants at all', function () {
    $admin = TestUser::make('Admin');
    $admin->roles = ['super_admin'];

    expect(Gate::forUser($admin)->allows('forumkit.forums.moderate'))->toBeTrue()
        ->and(Gate::forUser(TestUser::make('Nobody'))->allows('forumkit.forums.moderate'))->toBeFalse();
});

it('counts an active membership as paid, by the user model\'s own rule or the expiry column', function () {
    $check = new MembershipPaidAccess;

    $member = TestUser::make('Member');
    expect($check->hasPaidAccess($member))->toBeFalse();

    $member->membership_expires_at = now()->addDay();
    expect($check->hasPaidAccess($member))->toBeTrue();

    $member->membership_expires_at = now()->subDay();
    expect($check->hasPaidAccess($member))->toBeFalse();

    $custom = new class extends TestUser
    {
        public function hasActiveMembership(): bool
        {
            return true;
        }
    };
    expect($check->hasPaidAccess($custom))->toBeTrue();
});

it('opens a paid-only forum to a member, and a forum naming an entitlement to its holders', function () {
    $paid = makeForum(['visibility' => 'paid_only']);
    $scoped = makeForum(['visibility' => 'paid_only', 'required_entitlement_scope' => 'plugins.beta']);
    $member = TestUser::make('Member');
    $member->membership_expires_at = now()->addDay();

    freshServices();
    $access = app(ForumAccess::class);

    expect($access->canViewForum($paid, $member))->toBeTrue()
        ->and($access->canViewForum($scoped, $member))->toBeFalse();

    UserEntitlementGrant::create(['user_id' => $member->id, 'entitlement_key' => 'plugins.beta', 'grant_type' => 'manual']);
    app()->forgetInstance(ForumAccess::class);

    expect(app(ForumAccess::class)->canViewForum($scoped, $member))->toBeTrue();
});

it('moderates everything an "always moderate" user posts', function () {
    $user = TestUser::make('Always');
    $policy = new UserFlagsModerationPolicy;

    expect($policy->requiresModeration($user))->toBeFalse();

    $user->always_moderate_forum_content = true;

    expect($policy->requiresModeration($user))->toBeTrue()
        ->and($policy->awaitingFirstReview($user))->toBeFalse();
});

it('moderates a first-post user until something of theirs is approved, and holds them while it waits', function () {
    $user = TestUser::make('Newbie');
    $user->moderate_first_forum_post = true;
    $policy = new UserFlagsModerationPolicy;
    $forum = makeForum();

    expect($policy->requiresModeration($user))->toBeTrue()
        ->and($policy->awaitingFirstReview($user))->toBeFalse();

    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $user->id, 'title' => 'T', 'slug' => 't', 'moderation_status' => 'pending']);
    expect($policy->requiresModeration($user))->toBeTrue()
        ->and($policy->awaitingFirstReview($user))->toBeTrue();

    $thread->forceFill(['moderation_status' => 'approved'])->save();
    expect($policy->requiresModeration($user))->toBeFalse()
        ->and($policy->awaitingFirstReview($user))->toBeFalse();
});

it('does not hold a user who already has approved content, even with another submission pending', function () {
    $user = TestUser::make('Trusted');
    $user->moderate_first_forum_post = true;
    $forum = makeForum();
    Thread::create(['forum_id' => $forum->id, 'author_id' => $user->id, 'title' => 'A', 'slug' => 'a']);
    Thread::create(['forum_id' => $forum->id, 'author_id' => $user->id, 'title' => 'B', 'slug' => 'b', 'moderation_status' => 'pending']);

    expect((new UserFlagsModerationPolicy)->awaitingFirstReview($user))->toBeFalse();
});

it('counts an approved post as well as an approved thread', function () {
    $user = TestUser::make('Replier');
    $user->moderate_first_forum_post = true;
    $other = TestUser::make('Other');
    $thread = Thread::create(['forum_id' => makeForum()->id, 'author_id' => $other->id, 'title' => 'T', 'slug' => 't']);
    Post::create(['thread_id' => $thread->id, 'author_id' => $user->id, 'body' => 'b', 'content_format' => 'markdown', 'moderation_status' => 'approved']);

    expect((new UserFlagsModerationPolicy)->requiresModeration($user))->toBeFalse();
});

it('holds a first reply for approval and then refuses a second submission while it waits', function () {
    $author = TestUser::make('Op');
    $forum = makeForum();
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't'])->refresh();
    $newbie = TestUser::make('Newbie');
    $newbie->moderate_first_forum_post = true;
    freshServices();

    $first = app(Posting::class)->reply($newbie, $thread, 'My first post');

    expect($first->requiresApproval)->toBeTrue()
        ->and($first->post->moderation_status)->toBe(ContentModerationStatus::PENDING)
        ->and(fn () => app(Posting::class)->reply($newbie, $thread, 'Another'))->toThrow(PostingNotPermitted::class);
});

it('copies the user\'s signature onto new posts, and nothing when blank', function () {
    $resolver = new UserSignatureResolver;
    $user = TestUser::make('Signer');

    expect($resolver->signatureFor($user))->toBeNull();

    $user->signature = '   ';
    expect($resolver->signatureFor($user))->toBeNull();

    $user->signature = '-- Signer';
    expect($resolver->signatureFor($user))->toBe('-- Signer');
});

// ------------------------------------------------------------------ operator keys

it('maps the forum and entitlement abilities onto operator keys', function () {
    expect(config('forumkit-forums.gates'))->toBe([
        'forumkit.forums.moderate' => 'operator.forum.moderate',
        'forumkit.forums.view_unapproved' => 'operator.forum.view_unapproved',
        'forumkit.forums.bypass_approval' => 'operator.forum.bypass_approval',
    ])->and(config('forumkit-entitlements.gates'))->toBe([
        'forumkit.settings.manage' => 'operator.system.settings.manage',
        'forumkit.entitlements.manage' => 'operator.entitlements.manage',
        'forumkit.entitlements.view' => 'operator.entitlements.view',
    ]);
});

it('maps the logs ability onto the audit key, whether or not the logs package is installed', function () {
    expect(config('forumkit-logs.gates'))->toBe(['forumkit.logs.view' => 'operator.audit.view']);
});

it('allows an ability to the holder of the operator key, and not of the generic one', function () {
    $moderator = TestUser::make('Mod');
    UserEntitlementGrant::create(['user_id' => $moderator->id, 'entitlement_key' => 'operator.forum.moderate', 'grant_type' => 'manual']);
    $generic = TestUser::make('Generic');
    UserEntitlementGrant::create(['user_id' => $generic->id, 'entitlement_key' => 'forumkit.forums.moderate', 'grant_type' => 'manual']);

    expect(Gate::forUser($moderator)->allows('forumkit.forums.moderate'))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('forumkit.entitlements.manage'))->toBeFalse()
        ->and(Gate::forUser($generic)->allows('forumkit.forums.moderate'))->toBeFalse();

    freshServices();
    expect(app(ForumAccess::class)->canModerate($moderator))->toBeTrue()
        ->and(app(ForumAccess::class)->isStaff($moderator))->toBeTrue();
});

it('declares the operator keys as entitlement definitions when synced', function () {
    $this->artisan('forumkit:entitlements:sync')->assertExitCode(0);

    expect(EntitlementDefinition::where('key', 'operator.forum.moderate')->exists())->toBeTrue()
        ->and(EntitlementDefinition::where('key', 'operator.entitlements.manage')->exists())->toBeTrue();
});

it('seeds every operator definition with its label, once', function () {
    $this->seed(OperatorEntitlementSeeder::class);
    $this->seed(OperatorEntitlementSeeder::class);

    $labels = OperatorEntitlementSeeder::LABELS;

    expect(EntitlementDefinition::where('namespace', 'operator')->count())->toBe(count($labels))
        ->and(EntitlementDefinition::where('key', 'operator.forum.moderate')->value('label'))->toBe('Forum Moderation')
        ->and(UserEntitlementGrant::count())->toBe(0);
});

it('leaves a definition that already exists as it is', function () {
    EntitlementDefinition::create(['key' => 'operator.cms.manage', 'label' => 'Custom label']);

    $this->seed(OperatorEntitlementSeeder::class);

    expect(EntitlementDefinition::where('key', 'operator.cms.manage')->value('label'))->toBe('Custom label');
});
