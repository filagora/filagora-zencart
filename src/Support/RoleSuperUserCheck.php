<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Entitlements\Contracts\SuperUserCheck;
use Illuminate\Database\Eloquent\Model;

/**
 * Super administrators hold every entitlement. The role is read through the host user model's
 * `hasRole()`; a model without it has no super users.
 */
final class RoleSuperUserCheck implements SuperUserCheck
{
    public function bypasses(Model $user): bool
    {
        $role = (string) config('filagora-zencart.super_admin_role');

        return $role !== '' && method_exists($user, 'hasRole') && (bool) $user->hasRole($role);
    }
}
