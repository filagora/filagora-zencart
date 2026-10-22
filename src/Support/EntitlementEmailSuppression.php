<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Notifications\Contracts\EmailSuppression;
use Filagora\Notifications\Suppression\AttributeSuppression;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional notification mail stops for a person whose address is marked bounced or complained or
 * who is flagged as not receiving email (the package's attribute rule), and also for a person
 * who no longer holds the site's "can receive emails" entitlement. Every member is granted that
 * entitlement at registration; the command that quiets subscribers who have gone silent revokes
 * it, rather than writing to every individual choice.
 */
final class EntitlementEmailSuppression implements EmailSuppression
{
    public function __construct(
        private readonly AttributeSuppression $attributes,
        private readonly AccessChecker $access,
    ) {}

    public function isSuppressed(Authenticatable $user): bool
    {
        if ($this->attributes->isSuppressed($user)) {
            return true;
        }

        $key = config('filagora-zencart.notifications.email_entitlement');

        return is_string($key) && $key !== '' && ! $this->access->allows($user, $key);
    }
}
