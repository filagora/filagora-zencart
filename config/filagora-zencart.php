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
        'last_seen_at' => 'last_seen_at',
        'thread_subscription_email_frequency' => 'thread_subscription_email_frequency',
        'allow_private_messages' => 'allow_private_messages',
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
            'filagora.forums.announcements' => 'operator.site_notifications.manage',
        ],
        'filagora-entitlements.gates' => [
            'filagora.settings.manage' => 'operator.system.settings.manage',
            'filagora.entitlements.manage' => 'operator.entitlements.manage',
            'filagora.entitlements.view' => 'operator.entitlements.view',
        ],
        'filagora-compliance.gates' => [
            'filagora.compliance.privacy_requests.view' => 'operator.privacy.requests.view',
            'filagora.compliance.privacy_requests' => 'operator.privacy.requests.update',
            'filagora.compliance.erase' => 'operator.privacy.requests.delete',
            'filagora.compliance.cookie_policies' => 'operator.cookie_policies.manage',
            'filagora.compliance.privacy_policies' => 'operator.privacy_policies.manage',
            'filagora.compliance.audit' => 'operator.audit.view',
        ],
        'filagora-logs.gates' => [
            'filagora.logs.view' => 'operator.audit.view',
        ],
        'filagora-cms.gates' => [
            'filagora.cms.manage' => 'operator.cms.manage',
            'filagora.cms.preview' => 'operator.cms.preview',
        ],
        'filagora-memberships.gates' => [
            'filagora.memberships.manage' => 'operator.users.update',
            'filagora.memberships.view_billing' => 'operator.audit.view',
        ],
        'filagora-plugins.gates' => [
            'filagora.plugins.moderate' => 'operator.plugin.moderate',
            'filagora.plugins.auto_publish' => 'operator.plugin.auto_publish',
        ],
        'filagora-plugins-security.gates' => [
            'filagora.plugins.security' => 'operator.plugin.moderate',
        ],
        'filagora-notifications.gates' => [
            'filagora.notifications.view' => 'operator.users.update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | `email_entitlement`: members without this entitlement get no optional notification mail
    | (every member is granted it at registration; the command that quiets inactive subscribers
    | revokes it). Set to null to ignore it.
    |
    */
    'notifications' => [
        'email_entitlement' => 'notifications.email.enabled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | `stamp_manifest_id`: write a plugin's id into the manifest of a release's stored archive when
    | the release goes live, so a cart reads the number the version check knows. Switch off to
    | leave stored archives untouched.
    |
    */
    'plugins' => [
        'stamp_manifest_id' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin version check
    |--------------------------------------------------------------------------
    |
    | `routes`: register `/plugin_check.php` and `/plugincheck/{id}`, the URLs deployed carts call
    | (switch off to register them yourself). `cache_ttl_seconds`: how long shared caches may hold
    | an answer, empty answers too (0 turns the cache header off). `plugin_route` and `thread_route`
    | are the host's route names for a plugin's page and a forum thread, used for the `link` and
    | `support_thread` fields; null uses the plugins package's `routes.plugin` and `routes.thread`,
    | then `plugins.show` and `threads.show`. A field is left empty when the route does not exist.
    |
    */
    'versioncheck' => [
        'routes' => true,
        'cache_ttl_seconds' => 900,
        'plugin_route' => null,
        'thread_route' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu link kinds
    |--------------------------------------------------------------------------
    |
    | What a menu item of kind `legal_page`, `app_page` or `action` may point at; anything
    | else resolves to nothing and the item is dropped. Legal pages are whichever page the
    | named setting holds (a `/route` or a page slug). Application pages name a route of the
    | application (`member` ones take the signed-in user, and are `#` for a guest) or a path.
    |
    */
    'cms' => [
        'legal_pages' => [
            'privacy' => ['label' => 'Privacy policy', 'setting' => 'privacy_page', 'default' => '/privacy'],
            'cookie_policy' => ['label' => 'Cookie policy', 'setting' => 'cookie_policy_page', 'default' => '/cookie-policy'],
        ],
        'app_pages' => [
            'profile' => ['label' => 'Profile', 'route' => 'profile.show'],
            'my_profile' => ['label' => 'My public profile', 'route' => 'members.show', 'member' => true],
            'billing' => ['label' => 'Billing', 'route' => 'billing.index'],
            'messages' => ['label' => 'Messages', 'route' => 'messages.index'],
            'members' => ['label' => 'Members', 'route' => 'members.index'],
            'admin_dashboard' => ['label' => 'Admin dashboard', 'url' => '/dashboard'],
            'ai_chat' => ['label' => 'AI chat', 'route' => 'ai-chat.index'],
        ],
        'actions' => [
            'logout' => ['label' => 'Log out'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Erasure
    |--------------------------------------------------------------------------
    |
    | What the site's user row becomes when a person is erased (`{id}` is the user's id; columns
    | the table does not have are skipped). The password and remember token are always replaced.
    |
    */
    'anonymize' => [
        'attributes' => [
            'name' => 'Deleted User {id}',
            'username' => 'deleted_user_{id}',
            'email' => 'deleted-user-{id}@anonymized.invalid',
            'email_verified_at' => null,
            'avatar_path' => null,
            'signature' => null,
            'membership_expires_at' => null,
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
            'banned_until' => null,
            'thread_subscription_email_frequency' => 'instant',
            'allow_private_messages' => false,
            'legacy_password_hash' => null,
            'legacy_password_salt' => null,
            'legacy_password_algo' => null,
            'legacy_password_imported_at' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Version-check cache sync
    |--------------------------------------------------------------------------
    |
    | Push each plugin's version-check answer to the cache in front of Zen Cart stores (the
    | server-manager app) whenever the plugin or a release changes. `auth_token` is shared with
    | that app: set the same value on both sides.
    |
    */
    'cache_sync' => [
        'enabled' => env('PLUGIN_CACHE_SYNC_ENABLED', false),
        'base_url' => env('PLUGIN_CACHE_SYNC_BASE_URL', 'https://plugins.zen-cart.com'),
        'auth_token' => env('PLUGIN_CACHE_SYNC_AUTH_TOKEN'),
    ],

];
