<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use DateTimeInterface;
use Filagora\Forums\Contracts\PaidAccessCheck;
use Filagora\Memberships\Concerns\HasMemberships;
use Filagora\Memberships\Services\Memberships;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Paid" means an active membership: a current one in the memberships package, a
 * `membership_expires_at` in the future (what the site's members had before the package), or
 * whatever the user model's own `hasActiveMembership(): bool` says, if it defines one.
 */
final class MembershipPaidAccess implements PaidAccessCheck
{
    public function __construct(private readonly Memberships $memberships) {}

    public function hasPaidAccess(Authenticatable $user): bool
    {
        return ($user instanceof Model && $this->memberships->hasActive($user))
            || $this->hasUnexpiredMembership($user)
            || $this->userModelSaysSo($user);
    }

    /**
     * Whether the user's `membership_expires_at` is still ahead.
     */
    public function hasUnexpiredMembership(Authenticatable $user): bool
    {
        $expires = $user->{(string) config('filagora-zencart.user_attributes.membership_expires_at')} ?? null;

        return $expires instanceof DateTimeInterface && $expires->getTimestamp() > time();
    }

    private function userModelSaysSo(Authenticatable $user): bool
    {
        // The package's own trait asks the memberships service, which was asked above.
        if (in_array(HasMemberships::class, class_uses_recursive($user), true)) {
            return false;
        }

        return method_exists($user, 'hasActiveMembership') && (bool) $user->hasActiveMembership();
    }
}
