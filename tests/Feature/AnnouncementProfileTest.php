<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Forums\Support\Abilities;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Forums\Targeting\Viewer;
use Filagora\ZenCart\Seeders\OperatorEntitlementSeeder;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\Gate;

function zcRule(array $rule, ?TestUser $user = null): bool
{
    return app(TargetingRules::class)->passes([$rule], new Viewer($user));
}

it('adds the membership and not-visited rules to the forums ones', function () {
    expect(array_keys(app(TargetingRules::class)->all()))->toBe([
        'entitlement', 'username', 'date_is', 'time_between', 'never_posted', 'post_count_between', 'not_posted_days', 'browsing_forum',
        'membership', 'not_visited_days',
    ]);
});

it('passes the membership rule for an active membership, negated or not, and never for a guest', function () {
    $member = TestUser::make('Member');
    $member->membership_expires_at = now()->addMonth();
    $lapsed = TestUser::make('Lapsed');
    $lapsed->membership_expires_at = now()->subDay();

    expect(zcRule(['type' => 'membership'], $member))->toBeTrue()
        ->and(zcRule(['type' => 'membership'], $lapsed))->toBeFalse()
        ->and(zcRule(['type' => 'membership', 'negate' => true], $lapsed))->toBeTrue()
        ->and(zcRule(['type' => 'membership', 'negate' => true], $member))->toBeFalse()
        ->and(zcRule(['type' => 'membership', 'negate' => true]))->toBeFalse()
        ->and(zcRule(['type' => 'membership']))->toBeFalse();
});

it('asks the user model about a plan, and cannot confirm one it cannot ask about', function () {
    $user = new class extends TestUser
    {
        public function hasActiveMembershipForPlan(int $planId): bool
        {
            return $planId === 7;
        }
    };
    $user->membership_expires_at = now()->addMonth();

    expect(zcRule(['type' => 'membership', 'plan_id' => 7], $user))->toBeTrue()
        ->and(zcRule(['type' => 'membership', 'plan_id' => 8], $user))->toBeFalse()
        ->and(zcRule(['type' => 'membership', 'plan_id' => 8, 'negate' => true], $user))->toBeTrue();

    $plain = TestUser::make('Plain');
    $plain->membership_expires_at = now()->addMonth();

    expect(zcRule(['type' => 'membership', 'plan_id' => 7], $plain))->toBeFalse();
});

it('passes the not-visited rule when the last visit is old enough or unknown', function () {
    $away = TestUser::make('Away');
    $away->last_seen_at = now()->subDays(40);
    $here = TestUser::make('Here');
    $here->last_seen_at = now()->subDays(2);
    $unknown = TestUser::make('Unknown');

    expect(zcRule(['type' => 'not_visited_days', 'days' => 30], $away))->toBeTrue()
        ->and(zcRule(['type' => 'not_visited_days', 'days' => 30], $here))->toBeFalse()
        ->and(zcRule(['type' => 'not_visited_days', 'days' => 30], $unknown))->toBeTrue()
        ->and(zcRule(['type' => 'not_visited_days', 'days' => 0], $here))->toBeTrue()
        ->and(zcRule(['type' => 'not_visited_days', 'days' => 30]))->toBeFalse();

    config(['filagora-zencart.user_attributes.last_seen_at' => 'seen']);
    $here->seen = now()->subDays(90);
    expect(zcRule(['type' => 'not_visited_days', 'days' => 30], $here))->toBeTrue();
});

it('opens the announcements ability with the site\'s operator key, and defines it for the seeder', function () {
    $editor = TestUser::make('Editor');
    expect(Gate::forUser($editor)->allows(Abilities::ANNOUNCEMENTS))->toBeFalse();

    UserEntitlementGrant::create(['user_id' => $editor->id, 'entitlement_key' => 'operator.site_notifications.manage', 'grant_type' => 'manual']);

    expect(Gate::forUser($editor)->allows(Abilities::ANNOUNCEMENTS))->toBeTrue()
        ->and(OperatorEntitlementSeeder::LABELS)->toHaveKey('operator.site_notifications.manage');
});
