<?php

declare(strict_types=1);

namespace Filagora\ZenCart\CacheSync;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\ZenCart\Models\ZcVersion;

/**
 * Pushes a plugin's answer to the cache whenever the plugin or one of its releases changes, or a
 * Zen Cart version one of its releases names is renamed or removed. Only plugins that carts know
 * by an old library id are pushed: nothing else is ever asked about.
 */
final class SyncObserver
{
    public function saved(Plugin|PluginRelease|ZcVersion $model): void
    {
        if ($model instanceof ZcVersion && ! $model->wasChanged('version')) {
            return;
        }

        $this->push($model);
    }

    public function deleted(Plugin|PluginRelease|ZcVersion $model): void
    {
        if ($model instanceof ZcVersion) {
            return;
        }

        $this->push($model);
    }

    /**
     * Before the delete, while the releases that name the version can still be found.
     */
    public function deleting(Plugin|PluginRelease|ZcVersion $model): void
    {
        if ($model instanceof ZcVersion) {
            $this->push($model);
        }
    }

    private function push(Plugin|PluginRelease|ZcVersion $model): void
    {
        if (! (bool) config('filagora-zencart.cache_sync.enabled', false)) {
            return;
        }

        if ($model instanceof ZcVersion) {
            $pluginIds = $model->releases()->pluck('plugin_id')->unique()->all();

            Models::class('plugin')::query()->whereIn('id', $pluginIds)->get()->each(PushVersioncheckCache::queueFor(...));

            return;
        }

        PushVersioncheckCache::queueFor($model instanceof PluginRelease
            ? Models::class('plugin')::query()->find($model->plugin_id)
            : $model);
    }
}
