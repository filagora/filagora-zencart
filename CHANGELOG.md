# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

- Requires `filagora/filagora-memberships` `^0.1.1`. "Paid" for the forums (`MembershipPaidAccess`) now means a current membership in that package, a future `membership_expires_at`, or the user model's own `hasActiveMembership()` (skipped when the model uses the package's trait, which asks the same service). The profile's `membership` announcement rule keeps the legacy expiry for "has a membership" and leaves plan rules to the package's, replacing the package's rule in either registration order; the old rule that asked the user model about a plan is gone.
- The memberships gates are mapped: `filagora.memberships.manage` to `operator.users.update` and `filagora.memberships.view_billing` to `operator.audit.view`, which is what gated the same screens on the site.

- Requires `filagora/filagora-notifications` `^0.1`. The site's two own categories, `plugins.release_updates` (email, push and the bell; a mailed link may switch it off) and `privacy.request_updates` (email only), are registered after the package's. Optional mail now also stops for a member without the `notifications.email.enabled` entitlement (`EntitlementEmailSuppression`, `notifications.email_entitlement` in the config), on top of the package's `receives_email` and `email_status` rules. A change of the digest frequency or of private-message contactability is copied to the `thread_subscription_email_frequency` and `allow_private_messages` user columns that the rest of the site still reads (`SyncsLegacyNotificationColumns`). `filagora.notifications.view` is mapped to `operator.users.update`.

## [0.1.0] - 2026-10-17

First release: the Zen Cart profile for the generic Filagora packages. Checked against the live site's schema structure with `filagora:adopt --dry-run`: every package reports adoptable, with no differences.

- Requires `filagora/filagora-cms` `^0.3.1`.
- Requires `filagora/filagora-forums` `^0.6`, `filagora/filagora-messages` `^0.2` and `filagora/filagora-core` `^0.2.2`.
- Schema extension for `post_attachments`: the importer columns `legacy_attachment_id` (unique), `legacy_filedata_id`, `legacy_post_id`, `legacy_pmtext_id`, `legacy_state`, `legacy_download_count` and `legacy_created_at`, placed where the live table has them, and the lookups by post and by thread with the old attachment id, which replace the plain `post_id` and `thread_id` indexes (declared with core's `drop_indexes`). Its own migration, dated after the forums package's attachments migration and the message links `filagora-messages` adds. The `PostAttachment` model subclass behind `filagora.models.post_attachment`.
- The read tracking, mentions and message-link tables need no extras: they match the generic packages exactly.

- Requires `filagora/filagora-messages` `^0.1`. Schema extension for `private_conversations.legacy_root_pmid` and `private_messages.legacy_pmtext_id` (unique, after `id`), in its own migration directory.

- Requires `filagora/filagora-forums` `^0.4` (polls, reactions, subscriptions and digests).
- Schema extension for `reactions.legacy_reaction_id` (unique, after `id`) in its own migration, dated after the forums package's reactions migration, and the `Reaction` model subclass behind `filagora.models.reaction`. The thread subscription preference columns the forums package reads by default (`thread_subscription_email_frequency`, `auto_subscribe_on_post`) are the ones this site already has.

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
