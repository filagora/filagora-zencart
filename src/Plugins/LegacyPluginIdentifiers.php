<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Services\Visibility;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Finds the plugin a cart or script means. In order: the slug; the old library id
 * (`legacy_vbulletin_file_id`), which every deployed cart still knows plugins by; the install
 * folder (`zip_root`); the plugin's own id.
 */
final class LegacyPluginIdentifiers implements PluginIdentifiers
{
    public function __construct(private readonly Visibility $visibility, private readonly ZipInspector $zip) {}

    public function find(array $row, ?Authenticatable $viewer): ?Plugin
    {
        $slug = trim((string) ($row['slug'] ?? ''));

        if ($slug !== '') {
            return $this->visibility->findPublicPlugin($slug, $viewer);
        }

        $legacy = (int) ($row['legacy_vbulletin_file_id'] ?? 0);

        if ($legacy > 0) {
            return $this->visibility->publicPlugins($viewer)->where('legacy_vbulletin_file_id', $legacy)->first();
        }

        $root = $this->zip->normalizeRoot($row['zip_root'] ?? null);

        if ($root !== null) {
            return $this->visibility->publicPlugins($viewer)->where('zip_root', $root)->first();
        }

        $id = (int) ($row['id'] ?? 0);

        return $id > 0 ? $this->visibility->publicPlugins($viewer)->whereKey($id)->first() : null;
    }

    public function identifies(array $row): bool
    {
        return trim((string) ($row['slug'] ?? '')) !== ''
            || (int) ($row['legacy_vbulletin_file_id'] ?? 0) > 0
            || $this->zip->normalizeRoot($row['zip_root'] ?? null) !== null
            || (int) ($row['id'] ?? 0) > 0;
    }
}
