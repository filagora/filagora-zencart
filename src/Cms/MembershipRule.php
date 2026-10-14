<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Cms;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filagora\ZenCart\Support\MembershipPaidAccess;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * The user has (or, negated, has not) an active membership. With a plan id, it must be that
 * plan: the user model answers with `hasActiveMembershipForPlan(int $planId): bool`, and without
 * that method a plan can never be confirmed. A guest never passes.
 */
final class MembershipRule implements TargetingRule
{
    public function __construct(private readonly MembershipPaidAccess $membership) {}

    public function type(): string
    {
        return 'membership';
    }

    public function label(): string
    {
        return 'Has a membership';
    }

    public function schema(): array
    {
        return [
            TextInput::make('plan_id')->label('Plan ID (optional)')->numeric(),
            Toggle::make('negate')->label('Show to users who do NOT have it'),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        if ($viewer->isGuest()) {
            return false;
        }

        $planId = $rule['plan_id'] ?? null;

        if ($planId === null || $planId === '') {
            $has = $this->membership->hasPaidAccess($viewer->user);
        } else {
            $has = method_exists($viewer->user, 'hasActiveMembershipForPlan') && (bool) $viewer->user->hasActiveMembershipForPlan((int) $planId);
        }

        return ($rule['negate'] ?? false) ? ! $has : $has;
    }
}
