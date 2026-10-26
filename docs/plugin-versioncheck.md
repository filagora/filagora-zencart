# Plugin version check

The plugin version check is how a deployed Zen Cart store asks "is there a newer version of plugin N?". This profile answers it from the plugin library, and keeps the separate cache in front of stores up to date. Sister document: `docs/plugin-cache-sync.md` in the `server-manager` repository (the cache); read both before changing either side.

## Who calls it

The client is `VersionServer` in Zen Cart's own admin (`admin/includes/classes/VersionServer.php`), and it differs sharply by cart generation:

| cart generation | URL it calls | User-Agent | follows redirects? |
|---|---|---|---|
| v1.5.4 and older | `{site}/downloads.php?do=versioncheck&id=N` via `file_get_contents()` | **none**: PHP sends no User-Agent on stream wrappers | yes |
| v1.5.5 / v1.5.6 | `plugins.{site}/versioncheck/N` via curl | `Plugin Version Check [N] domain` | **no**: `CURLOPT_FOLLOWLOCATION` is never set |
| v1.5.7+ | `ping.{site}/plugincheck/N` (POST) | `Plugin Version Check [N] domain` or `[Batch] domain` | yes |

Plus a long tail of plugins that vendored the v1.5.4 function behind `if (!function_exists('plugin_version_check_for_updates'))` and still ship it, using the same call without a User-Agent.

Three consequences everything here depends on:

1. **No client can authenticate.** No Zen Cart version has ever shipped a credential for this site, and there is no way to distribute one. Any gate rejects every real store.
2. **A missing User-Agent is the normal case**, not a bot signature.
3. **A redirect reaches only some of them.** v1.5.5/1.5.6 cannot follow one, so the old `downloads.php` URL is answered in place (see "The old downloads.php URL").

The ping server (`server-manager`) sits in front of the v1.5.7+ population: it receives their POST, fetches `GET /plugincheck/{id}` from here with `User-Agent: ZC Pingserver`, and caches the result.

## The routes

```text
GET /plugin_check.php?do=versioncheck&id=2431
GET /plugin_check.php/versioncheck/2431
GET /plugin_check.php/2431
GET /plugincheck/2431
```

All four are handled by `VersionCheckController`, registered when `filagora-zencart.versioncheck.routes` is true (the default). They are **unauthenticated on purpose**: no User-Agent check, no signature. They are public GET requests with no session, so a shared cache will store them. Every answer is a one-element JSON array:

```json
[
  {
    "id": "2431",
    "title": "SkynetAccessibility Scanner",
    "latest_plugin_version": "v1.0.0",
    "latest_plugin_updated_date": "1771247354",
    "latest_plugin_updated_date_formatted": "16 Feb 2026",
    "filesize": "49470",
    "last_contributed_by": "skynettechnologiesus",
    "support_thread": "",
    "encapsulated": "1",
    "zcversions": ["v2.1.0", "v2.2.0"],
    "zcversions_csv": "v2.1.0, v2.2.0",
    "link": "https://www.example.org/plugins/skynetaccessibility-scanner"
  }
]
```

- The plugin is looked up by its old library id (`legacy_vbulletin_file_id`) first, then by its own id; only **active** plugins answer. An unknown id, an inactive plugin, or a request that is not a check answers `[]`.
- `latest_plugin_version` is the release's version folder when it has one, otherwise the version typed at upload.
- The release is the newest active one (`ReleaseSelection::latest()`), whatever Zen Cart versions it names.
- `link` is the host's plugin page and `support_thread` the path of its support thread: the host's route names are `versioncheck.plugin_route` and `versioncheck.thread_route`, falling back to the plugins package's `routes.plugin` and `routes.thread` and then to `plugins.show` and `threads.show`; a field is empty when its route does not exist.
- The field names and shapes are fixed by deployed carts. Do not change them.

### The old downloads.php URL

A host that keeps `downloads.php` calls the controller from its own route, so the answer is given in place and not redirected:

```php
if (trim((string) $request->query('do', '')) === 'versioncheck') {
    return app(\Filagora\ZenCart\VersionCheck\VersionCheckController::class)($request);
}
```

## Rate limiting

The routes use the `legacy-plugin-check` limiter: 20 a minute and 300 an hour per address and plugin, and 60 a minute and 600 an hour per address overall. It is **never keyed on the User-Agent**, so rotating agents does not get round it.

## Response caching

Every answer carries `Cache-Control: public, max-age=N, s-maxage=N`, with N from `versioncheck.cache_ttl_seconds` (default 900; 0 turns the header off). Empty answers are cached too.

These clients check on invocation, not on a schedule, and some plugins fire the check from storefront page loads, so ping volume follows each store's customer and spider traffic and has no ceiling. Per-address rate limiting is the wrong tool for that (the address is the store's server, so limiting it punishes a merchant for a plugin author's design), so caching decouples ping volume from origin load instead of rationing it. Without an explicit header the response defaults to `no-cache, private` and neither Cloudflare nor nginx will store it; the origin has to volunteer the header.

## History: do not reinstate a gate

For five months an earlier version of the site required a `Plugin Version Check [id] domain` User-Agent and then a timestamped HMAC signature on `/plugin_check.php`. Neither was satisfiable: the User-Agent gate turned away the v1.5.4-era clients (which send none), and the signature had no way to reach any store. The endpoint returned `403` to every real request. Because a `403` invites retry, the rejected clients never backed off. An endpoint with no reachable success state should not answer with a status that means "authenticate and try again". Before adding any gate, read "Who calls it" again.

## Cache sync

When a library plugin or one of its releases is saved or deleted, the profile sends the new answer to the version-check cache, so a store never waits for the cache to expire.

```text
PUT    {base_url}/versioncheck/{legacy_plugin_id}     form field: payload=<the one-element JSON array>
DELETE {base_url}/versioncheck/{legacy_plugin_id}
```

Each request carries the shared token twice, and a User-Agent naming the plugin:

```text
Authorization: Bearer <token>
X-Auth-Token: <token>
User-Agent: Plugin Cache Update [2431]
```

The receiver must accept either header and reject a missing or wrong token. A plugin with no answer (inactive, or no active release) is sent as a `DELETE`.

Configuration, in `config/filagora-zencart.php` under `cache_sync`, read from the same environment names the site used before:

```dotenv
PLUGIN_CACHE_SYNC_ENABLED=false
PLUGIN_CACHE_SYNC_BASE_URL=https://plugins.example.org
PLUGIN_CACHE_SYNC_AUTH_TOKEN=
```

The token must be the same value on both sides. A warning is logged when sync is enabled but the base URL or token is missing, and nothing is sent. Calls are retried twice with a short backoff.

The push is a queued job, unique and non-overlapping per plugin, so a burst of saves sends one update. To send everything (after the cache was emptied, before switching sync on, or after a change to the answer's shape, which otherwise stays out of date in the cache until that plugin is next saved):

```bash
php artisan filagora:zencart:push-versioncheck-cache            # queue every library plugin
php artisan filagora:zencart:push-versioncheck-cache --id=2431  # only these library ids
php artisan filagora:zencart:push-versioncheck-cache --now      # send here instead of queueing
```

Check queue depth first on a large library.

## Tests

`tests/Feature/VersionCheckTest.php` covers the shape, each URL form, a missing or misleading User-Agent (both must be answered), the version folder, the lookup order, inactive and unknown plugins, the cache header, no cookie, and the limiter with rotated User-Agents. `tests/Feature/CacheSyncTest.php` covers the headers on `PUT` and `DELETE`, the switch and the missing token, the observers, and the bulk command.
