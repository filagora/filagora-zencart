<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Cms;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filagora\Memberships\Forums\MembershipRule as PackageMembershipRule;
use Filagora\ZenCart\Support\MembershipPaidAccess;

/**
 * The memberships package's rule, with one difference: "has a membership" also counts a member
 * whose `membership_expires_at` is still ahead, as it did before the package. A rule on one plan
 * is the package's, since only the package knows plans.
 */
final class MembershipRule implements TargetingRule
{
    public function __construct(
        private readonly PackageMembershipRule $package,
        private readonly MembershipPaidAccess $paid,
    ) {}

    public function type(): string
    {
        return $this->package->type();
    }

    public function label(): string
    {
        return $this->package->label();
    }

    public function schema(): array
    {
        return $this->package->schema();
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        $planId = $rule['plan_id'] ?? null;

        if ($planId !== null && $planId !== '') {
            return $this->package->matches($rule, $viewer);
        }

        if ($viewer->isGuest()) {
            return false;
        }

        $has = $this->paid->hasPaidAccess($viewer->user);

        return ($rule['negate'] ?? false) ? ! $has : $has;
    }
}
