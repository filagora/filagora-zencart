<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Models\PluginRelease;
use Filagora\ZenCart\Models\Plugin;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts a release's plugin id into the manifest of its stored archive, then records the archive's
 * new size and checksum (the file changed, and downloads are checked against them).
 */
final class ManifestStamper
{
    public function __construct(private readonly ManifestPluginId $manifest) {}

    /**
     * The id a cart should read from this release's manifest.
     */
    public function expectedId(PluginRelease $release): int
    {
        $plugin = $release->plugin;

        return $plugin instanceof Plugin ? $plugin->versionCheckId() : (int) $release->plugin_id;
    }

    public function archivePath(PluginRelease $release): ?string
    {
        $path = trim((string) $release->zip_path);

        if ($path === '') {
            return null;
        }

        try {
            $disk = Storage::disk((string) config('filagora-plugins.storage.disk'));

            return $disk->exists($path) ? $disk->path($path) : null;
        } catch (Throwable) {
            // A disk with no local path (remote storage) cannot be rewritten in place.
            return null;
        }
    }

    /**
     * @return bool Whether the archive was changed.
     */
    public function stamp(PluginRelease $release): bool
    {
        $path = $this->archivePath($release);

        if ($path === null || ! $this->manifest->stamp($path, $this->expectedId($release))) {
            return false;
        }

        clearstatcache(true, $path);
        $release->forceFill(['zip_size' => filesize($path) ?: null, 'checksum_sha256' => hash_file('sha256', $path) ?: null])->saveQuietly();

        return true;
    }
}
