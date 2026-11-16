<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Filagora\Plugins\Support\Models;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Sends every library plugin's answer to the cache: after the cache was emptied, or before
 * switching sync on for the first time.
 *
 * The cache application limits writes per address (300 a minute at the time of writing) and a
 * refused write fails the job into its backoff, so the plugins are spread out: queued jobs are
 * delayed, and `--now` waits between sends. 240 a minute leaves room for ordinary pushes.
 */
final class PushAllCommand extends Command
{
    protected $signature = 'filagora:zencart:push-versioncheck-cache
        {--id=* : Only these old library ids}
        {--now : Send in this process instead of queueing}
        {--per-minute=240 : At most this many plugins a minute (0 for no limit)}';

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

        $perMinute = (int) $this->option('per-minute');

        if ($perMinute < 0) {
            $this->error('--per-minute must be 0 or more.');

            return self::INVALID;
        }

        $count = 0;

        $query->pluck('legacy_vbulletin_file_id')->each(function ($legacyId) use (&$count, $perMinute): void {
            if ($this->option('now')) {
                if ($count > 0 && $perMinute > 0) {
                    Sleep::for(60 / $perMinute)->seconds();
                }

                PushVersioncheckCache::dispatchSync((int) $legacyId);
            } else {
                $pending = PushVersioncheckCache::dispatch((int) $legacyId);

                if ($perMinute > 0) {
                    $pending->delay(now()->addSeconds(intdiv($count * 60, $perMinute)));
                }
            }

            $count++;
        });

        $this->info($count.' plugin(s) '.($this->option('now') ? 'sent' : 'queued').($perMinute > 0 ? ', at most '.$perMinute.' a minute' : '').'.');

        return self::SUCCESS;
    }
}
