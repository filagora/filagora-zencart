<?php

declare(strict_types=1);

namespace Filagora\ZenCart\VersionCheck;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plugin version check. Unauthenticated on purpose: no deployed Zen Cart holds a credential,
 * and a User-Agent or signature gate once turned every real store away for five months. It is
 * invokable so a host can call it from its own route (the old `downloads.php?do=versioncheck`
 * URL must be answered in place, not redirected, because older carts do not follow redirects).
 */
final class VersionCheckController
{
    public function __construct(
        private readonly PayloadBuilder $payloads,
        private readonly RequestResolver $requests,
    ) {}

    public function __invoke(Request $request, ?string $legacyPath = null): JsonResponse
    {
        [$mode, $id] = $this->requests->resolve($request, $legacyPath);

        if ($mode !== 'versioncheck' || $id === null) {
            return $this->cacheable(response()->json([]));
        }

        $payload = $this->payloads->forLegacyId($id);

        return $this->cacheable(response()->json($payload === null ? [] : [$payload]));
    }

    /**
     * Mark the response cacheable by shared caches. The answer for a plugin id is the same for every
     * caller and changes only when that plugin is saved, so it is safe to hold at the edge. Carts
     * check on invocation, not on a schedule, so the volume follows each store's own traffic and has
     * no ceiling; empty answers are cached on the same terms. A TTL of 0 switches this off.
     */
    private function cacheable(JsonResponse $response): JsonResponse
    {
        $ttl = (int) config('filagora-zencart.versioncheck.cache_ttl_seconds', 900);

        if ($ttl <= 0) {
            return $response;
        }

        $response->setPublic();
        $response->setMaxAge($ttl);
        $response->setSharedMaxAge($ttl);

        return $response;
    }
}
