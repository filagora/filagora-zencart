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
    | abilities the ForumKit packages define at those keys. They replace, not merge into,
    | the packages' own `gates` config.
    |
    */
    'gates' => [
        'forumkit-forums.gates' => [
            'forumkit.forums.moderate' => 'operator.forum.moderate',
            'forumkit.forums.view_unapproved' => 'operator.forum.view_unapproved',
            'forumkit.forums.bypass_approval' => 'operator.forum.bypass_approval',
        ],
        'forumkit-entitlements.gates' => [
            'forumkit.settings.manage' => 'operator.system.settings.manage',
            'forumkit.entitlements.manage' => 'operator.entitlements.manage',
            'forumkit.entitlements.view' => 'operator.entitlements.view',
        ],
        'forumkit-logs.gates' => [
            'forumkit.logs.view' => 'operator.audit.view',
        ],
    ],

];
