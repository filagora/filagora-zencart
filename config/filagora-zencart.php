<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Super administrator role
    |--------------------------------------------------------------------------
    |
    | Users whose model answers `hasRole()` true for this role hold every entitlement without
    | a grant. The host's user model supplies `hasRole()` (for example spatie/laravel-permission).
    |
    */
    'super_admin_role' => 'super_admin',

    /*
    |--------------------------------------------------------------------------
    | Host user attributes
    |--------------------------------------------------------------------------
    |
    | The columns this profile reads from the host's users table. It never adds them.
    |
    */
    'user_attributes' => [
        'always_moderate' => 'always_moderate_forum_content',
        'moderate_first_post' => 'moderate_first_forum_post',
        'signature' => 'signature',
        'membership_expires_at' => 'membership_expires_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator entitlement keys
    |--------------------------------------------------------------------------
    |
    | The site's staff abilities are `operator.*` entitlements. These maps point the Gate
    | abilities the Filagora packages define at those keys. They replace, not merge into,
    | the packages' own `gates` config.
    |
    */
    'gates' => [
        'filagora-forums.gates' => [
            'filagora.forums.moderate' => 'operator.forum.moderate',
            'filagora.forums.view_unapproved' => 'operator.forum.view_unapproved',
            'filagora.forums.bypass_approval' => 'operator.forum.bypass_approval',
        ],
        'filagora-entitlements.gates' => [
            'filagora.settings.manage' => 'operator.system.settings.manage',
            'filagora.entitlements.manage' => 'operator.entitlements.manage',
            'filagora.entitlements.view' => 'operator.entitlements.view',
        ],
        'filagora-logs.gates' => [
            'filagora.logs.view' => 'operator.audit.view',
        ],
    ],

];
