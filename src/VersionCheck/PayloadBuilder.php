<?php

declare(strict_types=1);

namespace Filagora\ZenCart\VersionCheck;

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\Plugins\Support\Models;
use Filagora\Plugins\Support\PluginVersion;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\PluginRelease as ZenCartRelease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The answer to a cart's "is there a newer version of plugin N": one plugin's newest release, in
 * the fields every deployed Zen Cart version reads. The shape is fixed by those carts and must not
 * change (see the package's docs/plugin-versioncheck.md).
 */
final class PayloadBuilder
{
    public function __construct(private readonly ReleaseSelection $selection) {}

    /**
     * Looks the plugin up by its old library id first (the number every cart knows it by), then by
     * its own id. Only active plugins answer.
     *
     * @return array<string, mixed>|null
     */
    public function forLegacyId(int $legacyId): ?array
    {
        if ($legacyId <= 0) {
            return null;
        }

        $plugin = Models::class('plugin')::query()
            ->where('legacy_vbulletin_file_id', $legacyId)
            ->where('status', PluginStatus::ACTIVE->value)
            ->first()
            ?? Models::class('plugin')::query()->whereKey($legacyId)->where('status', PluginStatus::ACTIVE->value)->first();

        if ($plugin === null) {
            return null;
        }

        /** @var list<ZenCartRelease> $releases */
        // The release model is swapped for the profile's subclass, which has zcVersions().
        $releases = Models::class('release')::query() // @phpstan-ignore larastan.relationExistence
            ->where('plugin_id', $plugin->getKey())
            ->where('status', PluginStatus::ACTIVE->value)
            ->with(['zcVersions', 'uploadedBy'])
            ->get()
            ->all();

        $release = $this->selection->latest($releases);

        if (! $release instanceof ZenCartRelease) {
            return null;
        }

        /** @var Model|null $uploader */
        $uploader = $release->uploadedBy;

        $updatedAt = $release->updated_at ?? $release->created_at;
        $zcVersions = $release->zcVersions
            ->pluck('version')
            ->filter(fn ($version): bool => is_string($version) && trim($version) !== '')
            ->map(fn (string $version): string => str_starts_with($version, 'v') ? $version : 'v'.$version)
            ->values();

        return [
            'id' => (string) $legacyId,
            'title' => (string) $plugin->name,
            'latest_plugin_version' => $release->version_folder ?? PluginVersion::canonical((string) $release->version) ?? (string) $release->version,
            'latest_plugin_updated_date' => $updatedAt !== null ? (string) $updatedAt->timestamp : '',
            'latest_plugin_updated_date_formatted' => $updatedAt !== null ? $updatedAt->format('d M Y') : '',
            'filesize' => (string) ((int) ($release->zip_size ?? 0)),
            'last_contributed_by' => (string) ($uploader?->getAttribute('username') ?? $uploader?->getAttribute('name') ?? ''),
            'support_thread' => $this->supportThread($plugin->forum_thread_id),
            'encapsulated' => $release->encapsulated === Encapsulated::YES ? '1' : '0',
            'zcversions' => $zcVersions,
            'zcversions_csv' => $zcVersions->implode(', '),
            'link' => $this->pluginLink($plugin),
        ];
    }

    /**
     * The path of the plugin's support thread, or an empty string. The route name is the host's:
     * `filagora-zencart.versioncheck.thread_route`.
     */
    private function supportThread(mixed $threadId): string
    {
        $route = (string) (config('filagora-zencart.versioncheck.thread_route') ?? config('filagora-plugins.routes.thread') ?? 'threads.show');

        if ($threadId === null || ! Route::has($route)) {
            return '';
        }

        return (string) route($route, ['thread' => $threadId], false);
    }

    /**
     * The plugin's public page. The route name is the host's: `filagora-zencart.versioncheck.plugin_route`, else the plugins package's `routes.plugin`.
     */
    private function pluginLink(object $plugin): string
    {
        $route = (string) (config('filagora-zencart.versioncheck.plugin_route') ?? config('filagora-plugins.routes.plugin') ?? 'plugins.show');

        if (! Route::has($route)) {
            return '';
        }

        return (string) route($route, $plugin);
    }
}
