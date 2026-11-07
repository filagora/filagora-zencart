<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Usage;

use Carbon\CarbonInterface;
use Filagora\Ai\Contracts\BillingAnchor;
use Filagora\Memberships\Models\UserMembership;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where a member's monthly AI window starts: the billing date of their membership. Of several, the one
 * whose plan grants the AI access sets the date, since that is the plan paying for it. A member with no
 * current membership (staff, for one) has no date and is counted by the calendar month.
 */
final class MembershipBillingAnchor implements BillingAnchor
{
    public function for(Authenticatable $user, array $preferEntitlements): ?CarbonInterface
    {
        $memberships = UserMembership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with('plan')
            ->get()
            ->filter(fn (UserMembership $membership): bool => $membership->isCurrentlyActive());

        if ($memberships->isEmpty()) {
            return null;
        }

        $preferred = $memberships->first(fn (UserMembership $membership): bool => array_intersect($preferEntitlements, $membership->plan?->entitlementList() ?? []) !== [])
            ?? $memberships->first();

        return $preferred->current_period_start ?? $preferred->starts_at;
    }
}
