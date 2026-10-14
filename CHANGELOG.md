# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

- Requires `filagora/filagora-compliance` `^0.1` and the contributor releases of entitlements, forums and logs.
- The six compliance abilities mapped to `operator.privacy.requests.view` / `.update` / `.delete`, `operator.cookie_policies.manage`, `operator.privacy_policies.manage` and `operator.audit.view` (all defined by the seeder).
- `ZenCartUserAnonymizer`, bound as compliance's `UserAnonymizer`: replaces the site's identity columns (`anonymize.attributes` in the config: name, username, email, avatar, signature, membership, ban fields, the legacy password columns; columns the table lacks are skipped), makes the password unusable, clears roles and permissions and two-factor when the user model has them, deletes the avatar file, sessions and password reset tokens. Authored content stays under the anonymous name.

- Requires `filagora/filagora-forums` `^0.3` (site announcements).
- Announcement targeting rules `membership` (any membership, or a plan through the user model's `hasActiveMembershipForPlan()`) and `not_visited_days` (the `last_seen_at` user attribute).
- `filagora.forums.announcements` mapped to `operator.site_notifications.manage`, which the seeder now defines.

- Requires `filagora/filagora-cms` `^0.2.1`.
- Schema extension for `cms_pages.legacy_vbulletin_content_id` (unique, after `id`) with its migration, in `database/migrations/cms`.
- The twelve site block types (`promo_hero`, `donation_hero`, `donation_method`, `services_hero`, `provider_grid`, `about_hero`, `home_*`) with forms, and the `download_*` aliases.
- Menu link kinds `legal_page`, `app_page` and `action`, allow-lists in `filagora-zencart.cms`.
- `filagora.cms.manage` and `filagora.cms.preview` mapped to `operator.cms.manage` and `operator.cms.preview`.

- **Renamed to Filagora.** The Composer package is `filagora/filagora-zencart` and the namespace `Filagora\ZenCart\`; the config file is `config/filagora-zencart.php`. It requires the `^0.2` Filagora packages.

- `forumkit.logs.view` (forumkit-logs) mapped to `operator.audit.view`.

- Schema extension for `forums`, `threads` and `posts` (four columns, three unique keys) with its migration.
- Model subclasses `Forum`, `Thread` and `Post`, registered under `forumkit.models`.
- Bindings: `RoleSuperUserCheck`, `MembershipPaidAccess`, `UserFlagsModerationPolicy`, `UserSignatureResolver`.
- Gate abilities of forums and entitlements mapped to `operator.*` keys (`config/forumkit-zencart.php`).
- `OperatorEntitlementSeeder`: the `operator.*` definitions, keys and labels only.
