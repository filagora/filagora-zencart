<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filagora\Ai\Contracts\BillingAnchor;
use Filagora\Ai\Usage\UsageMeterService;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\ZenCart\Ai\Usage\MembershipBillingAnchor;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\Gate;

/**
 * @param  list<string>  $entitlements
 */
function aiMembership(TestUser $user, string $periodStart, array $entitlements = ['ai_chat.access'], string $status = 'active'): UserMembership
{
    $plan = MembershipPlan::create(['code' => 'plan-'.uniqid(), 'name' => 'AI plan', 'is_active' => true, 'is_free' => false, 'provider' => 'paypal', 'entitlements' => $entitlements]);

    return UserMembership::create([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'status' => $status,
        'provider' => 'paypal',
        'subscription_name' => 'default-'.uniqid(),
        'starts_at' => CarbonImmutable::parse($periodStart),
        'current_period_start' => CarbonImmutable::parse($periodStart),
        'current_period_end' => CarbonImmutable::parse($periodStart)->addMonth(),
    ]);
}

beforeEach(function () {
    $this->member = TestUser::make('Member');
    $this->window = fn (string $now): array => app(UsageMeterService::class)->window($this->member, CarbonImmutable::parse($now));
});

it('is the billing anchor the meter uses', function () {
    expect(app(BillingAnchor::class))->toBeInstanceOf(MembershipBillingAnchor::class);
});

it('counts a monthly member from their billing date', function () {
    aiMembership($this->member, '2026-10-15 09:30:00');

    [$from, $until] = ($this->window)('2026-11-20 12:00:00');

    expect($from->toDateTimeString())->toBe('2026-11-15 09:30:00')->and($until->toDateTimeString())->toBe('2026-12-15 09:30:00');
});

it('counts someone with no membership by the calendar month', function () {
    expect(($this->window)('2026-11-18 10:00:00')[0]->toDateString())->toBe('2026-11-01');
});

it('lets the plan that grants the AI access set the date, not another membership', function () {
    aiMembership($this->member, '2026-10-01 00:00:00', ['forums.read']);
    aiMembership($this->member, '2026-10-20 00:00:00', ['ai_chat.access']);

    expect(($this->window)('2026-11-25 00:00:00')[0]->toDateString())->toBe('2026-11-20');
});

it('falls back to any current membership when none grants the access', function () {
    aiMembership($this->member, '2026-10-01 00:00:00', ['forums.read']);

    expect(($this->window)('2026-11-25 00:00:00')[0]->toDateString())->toBe('2026-11-01');
});

it('ignores a membership that has ended', function () {
    aiMembership($this->member, '2026-09-10 00:00:00', status: 'canceled');

    expect(($this->window)('2026-11-25 00:00:00')[0]->toDateString())->toBe('2026-11-01');
});

it('ignores someone else\'s membership', function () {
    aiMembership(TestUser::make('Other'), '2026-10-20 00:00:00');

    expect(($this->window)('2026-11-25 00:00:00')[0]->toDateString())->toBe('2026-11-01');
});

it('maps the usage screen to the audit entitlement and the chat to its own, as the live site has them', function () {
    expect(config('filagora-ai.gates'))->toBe([
        'filagora.ai.chat' => 'ai_chat.access',
        'filagora.ai.view-usage' => 'operator.audit.view',
    ]);

    expect(Gate::has('filagora.ai.view-usage'))->toBeTrue();
});
