# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Core's shared `filagora.moderate_frontend` ability maps to `operator.forum.moderate_frontend` (moderation on the site's pages, no dashboard forum screens), and `OperatorEntitlementSeeder` seeds its definition and label. Needs `filagora-core` 0.2.3, `filagora-entitlements` 0.2.3 and `filagora-forums` 0.6.5.

## [0.13.1] - 2026-11-08

### Fixed

- `docs:import` imported nothing in a site that did not happen to have `symfony/yaml`: the class was missing, the error was swallowed, and every page was reported as having no front matter. The package now requires `symfony/yaml`, and only a YAML parse error counts as "no front matter".

## [0.13.0] - 2026-11-07

### Added

- **A site's pages and pictures, from a directory it keeps.** The `create_site_content` migration creates the pages in `filagora-zencart.content.path` (`pages.php`), with their live blocks, and the pictures they show (`media.php`, files under `media/`, copied to the `public` disk and recorded in the media library), so a new site starts with its real pages. Only ever creates: a page that exists (by slug or by route) is left exactly as it is, a picture already on the disk is not overwritten, and rolling back removes nothing. `content.placeholders` fills `{name}` placeholders in the content; one with no value makes a link to it `#` and a `mailto:` link plain text. The content itself lives in the site, not here.

### Changed

- The menus migration moved to `database/migrations/content` (its name, and so its place in the migrations table, is unchanged).

## [0.12.0] - 2026-11-07

### Added

- **The site's menus, as a migration.** `2026_11_07_000000_create_zencart_navigation_menus` creates the header, member (`user_menu`), forum bar (`forum_header`) and the two footer menus (`footer_useful`, `footer_legal`), and the two reusable footer blocks (`footer_github`, `footer_newsletter`), with the live site's content as of 7 Nov 2026, so a new site starts with them instead of an empty top menu. It only ever creates: a menu or block that exists is left exactly as it is, so the live database and any site whose staff have edited them are untouched, and rolling it back removes nothing. The links to the documentation are filled in from `docs.base_url` and left out when there is none. Items use the entitlement format (the old `roles` key is gone).

## [0.11.0] - 2026-11-07

### Added

- **The AI usage meter's billing date and the usage screen's permission.** With `filagora-ai` 0.1.3, a member's monthly window is counted from their membership's billing date (`MembershipBillingAnchor`: of several memberships, the plan that grants the AI access sets the date; none, such as staff, means the calendar month). The usage screen's ability `filagora.ai.view-usage` maps to `operator.audit.view` and the chat's to `ai_chat.access` under `gates`.

## [0.10.1] - 2026-11-07

### Fixed

- The chat's code, documentation and plugin sources no longer build the search or the version list while the application boots: they take what they need when they are asked. A source in another package that built the forum access rules at boot broke a host's staff preview; these were not affected, but built too early all the same.

## [0.10.0] - 2026-11-06

### Added

- **Plugin release vectors for the AI chat**: the vector-only index `plugin_release_vectors` and a retrieval source (priority 400, after code, documentation and forum threads) offering the plugin's page, linked by the host's plugin route, with the changelog or the plugin's description as the excerpt.

### Added

- **The Zen Cart code corpus for the AI chat**, with the documentation corpus: tables `code_chunks` and `code_chunk_locations` (module `zencart-ai`), `CodeChunker` and `CodeIngester`, `zencart:ingest-code`, the vector-only `code_chunks` index (filled only by that command), the version list and detector, and a retrieval source (priority 100, ahead of the documentation) with its prompt rules. Config `code` (`ZENCART_CODE_REPO_URL`, `ZENCART_CODE_PATH`, `AI_CODE_RETRIEVAL_ENABLED`, `AI_CODE_RETRIEVAL_TOP_K`). The old site's ingest of its own source (`--site`) is not ported.

## [0.9.0] - 2026-11-06

### Added

- **The documentation corpus for the AI chat**, set up only when `filagora-ai` and `filagora-search` are installed: table `doc_pages` (module `zencart-ai`, migration and adoption snapshot), the `DocPage` model, `docs:import`, the `doc_pages` search index (keyword fields for the search page; vectors while embeddings are on, embedded a chunk at a time) and a retrieval source for the chat. Config `docs` (`ZENCART_DOCS_PATH`, `ZENCART_DOCS_REPO_URL`, `ZENCART_DOCS_REF`, `LEGACY_DOCS_URL`).

## [0.8.0] - 2026-11-04

- **`bug_reports` leaves the profile** for its own optional package, `filagora-forum-bugreports`. The migration keeps its file name, so a database that has run it does not run it again.

## [0.7.0] - 2026-11-04

- **Every table the site used to create.** The 12 migrations in `filagora-zencart-site` move here, as a module of their own (`zencart-site`) with a snapshot taken from the live structure: `users` and its session and reset tables, Laravel's cache, queue and notification tables, Sanctum's tokens, the newsletters, the webhook guard, the queue monitor, bug reports, contacts, the import map, the registration counter and the roles tables. `filagora:adopt` now compares all of them with the live tables, where before it never looked at them. They no longer skip a table that exists: adoption records them instead. The file names are kept, so a database that has run them does not run them again. A fresh build now matches the live shape for `jobs`, `failed_jobs`, `notifications` and two newsletter indexes. The models stay in the site for now.

## [0.6.0] - 2026-10-27

Two small additions the site needed while it moved onto the profile.

- **Library id collision guard.** A new plugin whose own id is already held by an imported plugin as its old library id is given an unused library id when it is created, so the version check cannot answer for the wrong plugin. `Plugin::versionCheckId()` returns the new id.
- **Old forum profile fallback.** `OldForumProfile::fill()` fills an imported member's empty biography, location, interests, occupation and real name on the model from the old vBulletin `vb_userfield` table, and `lastActivity()` reports the time they were last active from `vb_user`. Read-only, a no-op when the tables are missing, and switched off with `old_forum_profile.enabled` (`FILAGORA_ZENCART_OLD_FORUM_PROFILE`). Turn it off once the data has been copied into the users table.

## [0.5.0] - 2026-10-26

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
