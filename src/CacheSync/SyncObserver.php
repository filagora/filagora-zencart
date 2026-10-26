<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;

/**
 * Pushes a plugin's answer to the cache whenever the plugin or one of its releases changes. Only
 * plugins that carts know by an old library id are pushed: nothing else is ever asked about.
 */
final class SyncObserver
{
    public function saved(Plugin|PluginRelease $model): void
    {
        $this->push($model);
    }

    public function deleted(Plugin|PluginRelease $model): void
    {
        $this->push($model);
    }

    private function push(Plugin|PluginRelease $model): void
    {
        if (! (bool) config('filagora-zencart.cache_sync.enabled', false)) {
            return;
        }

        $plugin = $model instanceof PluginRelease
            ? Models::class('plugin')::query()->find($model->plugin_id)
            : $model;

        $legacyId = (int) ($plugin?->getAttribute('legacy_vbulletin_file_id') ?? 0);

        if ($legacyId > 0) {
            PushVersioncheckCache::dispatch($legacyId);
        }
    }
}
