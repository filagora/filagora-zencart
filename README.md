# Filagora Zen Cart

The Zen Cart profile for Filagora: everything zen-cart.com needs on top of the generic
[`filagora-forums`](https://github.com/filagora/filagora-forums),
[`filagora-entitlements`](https://github.com/filagora/filagora-entitlements) and
[`filagora-core`](https://github.com/filagora/filagora-core) packages, kept out of them so a
normal install never carries it. It has no screens and no routes. It is useful to anyone who
wants to run the Zen Cart support site's forum rules (staging and test setups do), and as a
worked example of a Filagora profile package.

> The package is at `0.1`: the API may change between `0.x` minor versions.

## What it does

- **Declares the site's extra columns**, exactly, so `php artisan filagora:adopt` accepts the
  live tables and still reports anything undeclared: `forums.legacy_vbulletin_forum_id`
  (unique), `forums.is_plugin_support_forum`, `threads.legacy_vbulletin_thread_id` (unique) and
  `posts.legacy_post_id` (unique). A migration creates them, in the same place the live tables
  have them, for fresh development and test databases; on adoption it is recorded as run.
- **Swaps in model subclasses** (`Filagora\ZenCart\Models\Forum`, `Thread`, `Post`) that know
  the extra columns, through `filagora.models`. A model you already set there is kept.
- **Binds the Zen Cart rules** to the packages' extension points:

  | Contract | Rule |
  | --- | --- |
  | `SuperUserCheck` | users with the `super_admin` role (read through `hasRole()` on your user model) hold every entitlement |
  | `PaidAccessCheck` | "paid" is an active membership: your user model's `hasActiveMembership()`, or a `membership_expires_at` in the future |
  | `ContentModerationPolicy` | the user flags `always_moderate_forum_content` and `moderate_first_forum_post`: first-post users are moderated until something of theirs is approved, and may not post again while their first submission waits |
  | `PostSignatureResolver` | the user's `signature` column |

- **Maps the staff abilities to `operator.*` entitlement keys** (`filagora.forums.moderate` to
  `operator.forum.moderate`, `filagora.entitlements.manage` to `operator.entitlements.manage`,
  `filagora.logs.view` to `operator.audit.view`, and so on), replacing the packages' `gates` config. The map lives in
  `config/filagora-zencart.php`.
- **Extends the cms package**: declares `cms_pages.legacy_vbulletin_content_id` (unique) the same
  way; registers the site's twelve block types (`promo_hero`, `donation_hero`, `donation_method`,
  `services_hero`, `provider_grid`, `about_hero` and the `home_*` blocks) with their forms, and
  the old `download_*` names as aliases (`download_hero` is `promo_hero`, and so on); registers
  the menu link kinds `legal_page` (the page the `privacy_page` / `cookie_policy_page` settings
  name), `app_page` and `action` (logout), whose allowed references and route names are in
  `config/filagora-zencart.php` under `cms`; and maps `filagora.cms.manage` / `.preview` to
  `operator.cms.manage` / `.preview`.
- **Adds to the forums package's site announcements**: the targeting rules `membership` and
  `not_visited_days` (needs a `last_seen_at` column on your user model, configurable), and maps
  the announcements ability to `operator.site_notifications.manage`.
- **Extends the compliance package**: maps its six abilities to `operator.privacy.requests.view`,
  `.update` and `.delete`, `operator.cookie_policies.manage`, `operator.privacy_policies.manage`
  and `operator.audit.view`; and binds `ZenCartUserAnonymizer`, which replaces the site's identity
  columns on erasure (list in `anonymize.attributes`), makes the password unusable, clears roles,
  permissions and two-factor, and removes the avatar file, sessions and password reset tokens.
  What your application keeps in its own tables (subscriptions, messages, contacts,
  notifications, newsletter subscriber rows) is erased by a `PrivacyContributor` you register
  with core for each, not by this package.
- **Seeds entitlement definitions** (`OperatorEntitlementSeeder`): the `operator.*` keys with
  their labels, for staging and test setups. It never creates grants, users or content.

It reads these columns from your users table and never adds them: `always_moderate_forum_content`,
`moderate_first_forum_post`, `signature`, `membership_expires_at` (the names are configurable
under `user_attributes`).

## Install

```bash
composer require filagora/filagora-zencart
php artisan migrate
php artisan filagora:entitlements:sync
php artisan db:seed --class='Filagora\ZenCart\Seeders\OperatorEntitlementSeeder'
```

The site's tables have no prefix, so set `FILAGORA_TABLE_PREFIX=` (empty) in the environment
before you migrate or adopt. To start from a database that already holds the tables, run
`php artisan filagora:adopt --dry-run` instead of `migrate`: core, entitlements and forums, with
this package's extras, must each report adoptable with no differences.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
