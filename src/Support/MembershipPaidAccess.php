<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use DateTimeInterface;
use Filagora\Forums\Contracts\PaidAccessCheck;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Paid" means an active membership. The host user model decides what that is by defining
 * `hasActiveMembership(): bool`; failing that, a `membership_expires_at` in the future counts.
 */
final class MembershipPaidAccess implements PaidAccessCheck
{
    public function hasPaidAccess(Authenticatable $user): bool
    {
        if (method_exists($user, 'hasActiveMembership')) {
            return (bool) $user->hasActiveMembership();
        }

        $expires = $user->{(string) config('filagora-zencart.user_attributes.membership_expires_at')} ?? null;

        return $expires instanceof DateTimeInterface && $expires->getTimestamp() > time();
    }
}
