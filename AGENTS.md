# AGENTS

Guidance for AI coding agents working in `filagora-zencart`. `CLAUDE.md` imports this file; edit here.

## What this is

**Private** package (GitHub release only, not on Packagist). The Zen Cart profile for Filagora: the extra columns, indexes, model subclasses, key remaps, rules, seeders and cms block types that the Zen Cart support site needs on top of the generic packages, kept out of them so a normal install never carries it. It has no screens and no routes. Part of Filagora, a family of MIT-licensed Laravel 13 / Filament 5 packages (forums, CMS, entitlements, logs, compliance, messages, search, push, memberships). Composer vendor `filagora`, namespace `Filagora\<Name>\`, default table prefix `filagora_`, commands `filagora:*`, env `FILAGORA_*`. Every package is pre-1.0 (`0.x`): the API may change between minor versions.

## What it contains

- **Schema extensions** (`database/extensions`) declaring the site's extra columns/indexes exactly, so `filagora:adopt` accepts the live tables and still reports anything undeclared; migrations create the same columns for fresh dev/test databases and are recorded as run on adoption.
- **Model subclasses** swapped in via `filagora.models` (`Models/`).
- **Rule bindings** to the packages' extension points (super-user check, paid access, content moderation policy, post signature resolver, and similar).
- **Ability map** in `config/filagora-zencart.php`, turning `filagora.*` staff abilities into `operator.*` entitlement keys.
- **cms extras** (`Cms/`): the site's block types and old-name aliases, menu link kinds.
- **Seeders** (`Seeders/`).
- **Notifications**: the site's own categories, the entitlement-aware email suppression, and the copy of two choices into the user columns other parts of the site still read.

## Working here

- The target is the live production database: every column, index, default and stored value must match what production holds, and `filagora:adopt --dry-run` must stay at zero differences. The authoritative schema material is in the private planning repository (`../filagora-planning`: `schema-parity.md`, `filagora-zencart-plan.md`, `artifacts/`). Read those before changing a table declaration.
- It may name the origin site here (the repo is private), but never copy private details into any public Filagora repository.
- Do not push generic behaviour in here. If a rule is useful to anyone, it belongs in the owning package behind a contract; this package only binds site-specific answers.
- The private repo has no branch protection (free plan): still use a branch and PR.
- The memberships and notifications wiring is merged after `v0.1.0` and untagged. Confirm before tagging.

## Commands

Run everything through DDEV from this directory (`ddev start` once; the `testing` database is created on the first `ddev restart`).

```bash
ddev composer install
ddev composer test      # Pest, on a real MariaDB
ddev composer lint      # Pint
ddev composer analyse   # Larastan (level 6)
```

Tests always run against MariaDB (database `testing`, forced in `phpunit.xml.dist`), never SQLite, so FULLTEXT, `ONLY_FULL_GROUP_BY` and collation behave like production. Do not weaken that to make a test pass. If a port is taken, `ddev poweroff` before starting another project's DDEV.

## Conventions

- PHP 8.4, `declare(strict_types=1)` in every source file (`tests/ArchTest.php` enforces it, plus no debug calls and no `env()` outside `config/`).
- Table names always go through core's `Tables::name('x')` (prefix-aware); user foreign keys through `UserKey`/`UserModel`. Never hard-code `filagora_` or `users`.
- Config holds no closures and is merged with the host's copy, so list only what differs. Env vars are `FILAGORA_`-prefixed.
- Authorization is one question, "may this user do this?", answered by core's `AccessChecker` from the package's `gates` config. Do not call a roles or permissions package directly.
- Extend through documented contracts, events and registries. Anything undocumented is `@internal`; public API follows SemVer.
- Migrations are mirrored by a snapshot in `database/schema.php` (`filagora:schema:dump`). A table change means updating the migration, the snapshot and the adoption check together, because `filagora:adopt` compares live tables against the snapshot.

## Definition of done

- Every behaviour change ships with tests; every bug fix with a regression test that fails before the fix.
- README and `CHANGELOG.md` (`[Unreleased]`) are updated in the same change.
- `composer test`, `composer lint` and `composer analyse` pass.
- Work on a branch and open a pull request (`gh pr merge --squash --delete-branch`). `master` is protected and squash-only.
- Confirm with the user before tagging a release, creating or deleting repositories or branches, or anything else outward-facing. Tags create the GitHub release from the matching `CHANGELOG.md` section; the user submits new packages to Packagist.

## CI

`.github/workflows/ci.yml` calls the shared workflows in `filagora/filagora-shared`.
