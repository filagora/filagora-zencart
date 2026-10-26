# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

The plugin version check and its cache sync (slices 8c and 8d), and the Zen Cart screens (slice 8e).

- **Version check (8c).** `/plugin_check.php` (query form and path form) and `/plugincheck/{id}`, registered unless `versioncheck.routes` is false: the one-element JSON array every deployed cart reads, looked up by old library id then own id, active plugins only, newest release, unauthenticated by design (no User-Agent check, no signature), public-cacheable for `versioncheck.cache_ttl_seconds` (default 900; 0 turns it off), empty answers too, limited by the `legacy-plugin-check` limiter (per address and plugin, plus a per-address cap, never keyed on the User-Agent). `VersionCheckController` is invokable so a host can answer the old `downloads.php?do=versioncheck` URL in place. `versioncheck.plugin_route` and `thread_route` name the host's pages for `link` and `support_thread`. See `docs/plugin-versioncheck.md`.
- **Cache sync (8d).** A queued, unique job pushes a library plugin's answer to the version-check cache when the plugin or one of its releases is saved or deleted (`PUT` with a `payload` field, `DELETE` when it no longer answers; bearer token plus `X-Auth-Token`; user agent `Plugin Cache Update [id]`), off unless `cache_sync.enabled` (`PLUGIN_CACHE_SYNC_ENABLED`, `_BASE_URL`, `_AUTH_TOKEN`). `filagora:zencart:push-versioncheck-cache` (`--id`, `--now`) sends everything. Difference from the site this was extracted from: releases are observed too, so a release change reaches the cache without the plugin being saved.
- **Screens (8e).** `FilagoraZenCartPlugin` adds the Zen Cart versions screen (list, create, edit, delete; gated like the plugin screens); the plugin release list gains encapsulated and Zen Cart versions columns and filters through the plugins package's `ReleaseScreens`.

## [0.4.0] - 2026-10-23

The plugin library on the profile: the schema (slice 8a) and plugin uploads (slice 8b). Requires `filagora/filagora-plugins` `^0.3`. Checked against the live site's schema structure with `filagora:adopt --dry-run`: every package, including `plugins`, `plugins-security` and this profile's own `zencart` module (the two Zen Cart version tables), reports adoptable with no differences. A site on `v0.3.0` must also install `filagora/filagora-plugins`.

- Plugin uploads (slice 8b). `ZcVersionCompatibility` answers the plugins package's `Compatibility` from the Zen Cart versions ticked on a release (a leading `v` is ignored; a release naming no version matches no particular one). `ZenCartReleaseExtension` reads `encapsulated` (`yes`, `no`, `unknown`) and `zc_versions` (ids) from an upload: for an encapsulated archive it needs one install folder that no other plugin uses and that matches the plugin's own, and a version folder that is the version typed, then stores `version_folder`, syncs the Zen Cart versions and gives the plugin its install folder. `ZenCartApiFields` adds the library id, install folder, encapsulated flag, version folder and Zen Cart versions to the API; `LegacyPluginIdentifiers` finds a plugin by slug, old library id, install folder or id.
- The plugin's id is written into the `manifest.php` of a release's stored archive when the release goes live (on approval, or at once for an author who may publish): `StampManifestPluginId`, switched off with `plugins.stamp_manifest_id`, and `filagora:zencart:stamp-manifest-ids` (`--plugin`, `--dry-run`) for the releases already live. The plugin gates are mapped to `operator.plugin.moderate` and `operator.plugin.auto_publish`, and the security gate to `operator.plugin.moderate`.
- Differences from the site this was extracted from: an archive whose manifests name more than one plugin, or that only has a `zc_plugins` folder with no manifest, is refused instead of being given `zc_plugins` as its install folder; and after the archive is stamped its size and checksum are recorded again (the site kept the numbers of the unstamped file).

- Requires `filagora/filagora-plugins` `^0.3`. The schema the site's plugin library has beyond the generic package, declared so `filagora:adopt` accepts the live tables: on `plugins` the old library id (`legacy_vbulletin_file_id`, unique) and the install folder (`zip_root`, unique); on `plugin_releases` the `version_folder` and the `encapsulated` flag (`yes`, `no`, `unknown`); and the two whole tables `plugin_zc_versions` and `plugin_release_zc_versions` (the Zen Cart versions a release works with), as a snapshot of their own under the `zencart` module. Each is checked against the live definitions: a dry-run of `filagora:adopt` against the production structure reports `plugins`, `plugins-security` and `zencart` all adoptable.
- Model subclasses `Plugin` (`versionCheckId()`: the library id a cart asks about, or the plugin's own id) and `PluginRelease` (`encapsulated` as the `Encapsulated` enum, `zcVersions()`), swapped in through `filagora.models`, and the `ZcVersion` model.

## [0.3.0] - 2026-10-22

The profile on `filagora-messages` `^0.3` (ignore lists). Checked against the live site's schema structure with `filagora:adopt --dry-run`: every package reports adoptable with no differences; messages now records three migrations (its tables, the ignore list, and this profile's columns). A site on `v0.2.0` must move `filagora/filagora-messages` to `^0.3`.

- Requires `filagora/filagora-messages` `^0.3` (ignore lists: the `ignored_users` table, which the live site has with the same shape, so `filagora:adopt` records one more migration for messages). Sending now also honours ignore lists, together with the member's own "accept private messages" choice.

## [0.2.0] - 2026-10-22

The profile for the memberships and notifications packages. Checked against the live site's schema structure with `filagora:adopt --dry-run`: every package, notifications included, reports adoptable with no differences.

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
