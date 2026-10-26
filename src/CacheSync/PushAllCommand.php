<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Filagora\Plugins\Support\Models;
use Illuminate\Console\Command;

/**
 * Sends every library plugin's answer to the cache: after the cache was emptied, or before
 * switching sync on for the first time.
 */
final class PushAllCommand extends Command
{
    protected $signature = 'filagora:zencart:push-versioncheck-cache
        {--id=* : Only these old library ids}
        {--now : Send in this process instead of queueing}';

    protected $description = 'Send the plugin version-check answers to the version-check cache.';

    public function handle(CacheSyncClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('Cache sync is not enabled (filagora-zencart.cache_sync).');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        $query = Models::class('plugin')::query()->whereNotNull('legacy_vbulletin_file_id')->orderBy('legacy_vbulletin_file_id');

        if ($ids !== []) {
            $query->whereIn('legacy_vbulletin_file_id', $ids);
        }

        $count = 0;

        $query->pluck('legacy_vbulletin_file_id')->each(function ($legacyId) use (&$count): void {
            $this->option('now')
                ? PushVersioncheckCache::dispatchSync((int) $legacyId)
                : PushVersioncheckCache::dispatch((int) $legacyId);
            $count++;
        });

        $this->info($count.' plugin(s) '.($this->option('now') ? 'sent' : 'queued').'.');

        return self::SUCCESS;
    }
}
