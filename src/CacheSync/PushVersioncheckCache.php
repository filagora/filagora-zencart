<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Filagora\ZenCart\VersionCheck\PayloadBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends one plugin's current version-check answer to the cache, or removes it when the plugin no
 * longer answers. Unique and non-overlapping per plugin, so a burst of saves sends one update.
 */
final class PushVersioncheckCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 180;

    public function __construct(public readonly int $legacyPluginId) {}

    public function uniqueId(): string
    {
        return 'plugin-versioncheck-cache-sync:'.$this->legacyPluginId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(180)];
    }

    public function handle(PayloadBuilder $payloads, CacheSyncClient $client): void
    {
        if ($this->legacyPluginId <= 0) {
            return;
        }

        $payload = $payloads->forLegacyId($this->legacyPluginId);

        if ($payload === null) {
            $client->pushDelete($this->legacyPluginId);

            return;
        }

        $client->pushUpdate($this->legacyPluginId, (string) json_encode([$payload], JSON_UNESCAPED_SLASHES));
    }
}
