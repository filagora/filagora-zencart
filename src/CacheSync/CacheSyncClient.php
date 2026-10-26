<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the version-check cache in front of Zen Cart stores (the separate server-manager app) what
 * a plugin's answer now is, so a store never waits for the cache to expire. Switched off unless
 * `filagora-zencart.cache_sync.enabled`; talks to `{base_url}/versioncheck/{id}` with the shared
 * token as both a bearer token and an `X-Auth-Token` header.
 */
final class CacheSyncClient
{
    private const TOKEN_HEADER = 'X-Auth-Token';

    public function pushUpdate(int $legacyId, string $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $response = $this->request($legacyId)->retry(2, 200)->asForm()->put($this->url($legacyId), ['payload' => $payload]);

        if (! $response->successful()) {
            Log::warning('Plugin cache sync update failed.', ['legacy_plugin_id' => $legacyId, 'status' => $response->status(), 'response' => $response->body()]);
        }
    }

    public function pushDelete(int $legacyId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $response = $this->request($legacyId)->retry(2, 200)->delete($this->url($legacyId));

        if (! $response->successful()) {
            Log::warning('Plugin cache sync delete failed.', ['legacy_plugin_id' => $legacyId, 'status' => $response->status(), 'response' => $response->body()]);
        }
    }

    public function enabled(): bool
    {
        if (! (bool) config('filagora-zencart.cache_sync.enabled', false)) {
            return false;
        }

        if (trim((string) config('filagora-zencart.cache_sync.base_url', '')) === '' || $this->token() === '') {
            Log::warning('Plugin cache sync is enabled but its base URL or auth token is missing.');

            return false;
        }

        return true;
    }

    private function url(int $legacyId): string
    {
        return rtrim((string) config('filagora-zencart.cache_sync.base_url', ''), '/').'/versioncheck/'.$legacyId;
    }

    private function token(): string
    {
        return trim((string) config('filagora-zencart.cache_sync.auth_token', ''));
    }

    private function request(int $legacyId): PendingRequest
    {
        return Http::timeout(5)
            ->connectTimeout(5)
            ->withToken($this->token())
            ->withHeaders([self::TOKEN_HEADER => $this->token()])
            ->withUserAgent('Plugin Cache Update ['.$legacyId.']');
    }
}
