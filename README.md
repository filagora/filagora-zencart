# ForumKit Zen Cart

The Zen Cart profile for ForumKit: everything zen-cart.com needs on top of the generic
[`forumkit-forums`](https://github.com/zcwilt/forumkit-forums),
[`forumkit-entitlements`](https://github.com/zcwilt/forumkit-entitlements) and
[`forumkit-core`](https://github.com/zcwilt/forumkit-core) packages, kept out of them so a
normal install never carries it. It has no screens and no routes. It is useful to anyone who
wants to run the Zen Cart support site's forum rules (staging and test setups do), and as a
worked example of a ForumKit profile package.

> The package is at `0.1`: the API may change between `0.x` minor versions.

## What it does

- **Declares the site's extra columns**, exactly, so `php artisan forumkit:adopt` accepts the
  live tables and still reports anything undeclared: `forums.legacy_vbulletin_forum_id`
  (unique), `forums.is_plugin_support_forum`, `threads.legacy_vbulletin_thread_id` (unique) and
  `posts.legacy_post_id` (unique). A migration creates them, in the same place the live tables
  have them, for fresh development and test databases; on adoption it is recorded as run.
- **Swaps in model subclasses** (`ForumKit\ZenCart\Models\Forum`, `Thread`, `Post`) that know
  the extra columns, through `forumkit.models`. A model you already set there is kept.
- **Binds the Zen Cart rules** to the packages' extension points:

  | Contract | Rule |
  | --- | --- |
  | `SuperUserCheck` | users with the `super_admin` role (read through `hasRole()` on your user model) hold every entitlement |
  | `PaidAccessCheck` | "paid" is an active membership: your user model's `hasActiveMembership()`, or a `membership_expires_at` in the future |
  | `ContentModerationPolicy` | the user flags `always_moderate_forum_content` and `moderate_first_forum_post`: first-post users are moderated until something of theirs is approved, and may not post again while their first submission waits |
  | `PostSignatureResolver` | the user's `signature` column |

- **Maps the staff abilities to `operator.*` entitlement keys** (`forumkit.forums.moderate` to
  `operator.forum.moderate`, `forumkit.entitlements.manage` to `operator.entitlements.manage`,
  and so on), replacing the packages' `gates` config. The map lives in
  `config/forumkit-zencart.php`.
- **Seeds entitlement definitions** (`OperatorEntitlementSeeder`): the `operator.*` keys with
  their labels, for staging and test setups. It never creates grants, users or content.

It reads these columns from your users table and never adds them: `always_moderate_forum_content`,
`moderate_first_forum_post`, `signature`, `membership_expires_at` (the names are configurable
under `user_attributes`).

## Install

```bash
composer require zcwilt/forumkit-zencart
php artisan migrate
php artisan forumkit:entitlements:sync
php artisan db:seed --class='ForumKit\ZenCart\Seeders\OperatorEntitlementSeeder'
```

The site's tables have no prefix, so set `FORUMKIT_TABLE_PREFIX=` (empty) in the environment
before you migrate or adopt. To start from a database that already holds the tables, run
`php artisan forumkit:adopt --dry-run` instead of `migrate`: core, entitlements and forums, with
this package's extras, must each report adoptable with no differences.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
