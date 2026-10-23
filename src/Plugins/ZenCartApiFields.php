<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\PluginRelease as ZenCartPluginRelease;
use Illuminate\Validation\Rule;

/**
 * The Zen Cart fields of the plugin API: the library id and install folder on a plugin; the
 * encapsulated flag, version folder and Zen Cart versions on a release, which an upload may also
 * send (`encapsulated`, and `zc_versions` as a list of ids).
 */
final class ZenCartApiFields implements ApiFields
{
    public function pluginFields(Plugin $plugin): array
    {
        return [
            'legacy_vbulletin_file_id' => $plugin->getAttribute('legacy_vbulletin_file_id') !== null ? (int) $plugin->getAttribute('legacy_vbulletin_file_id') : null,
            'zip_root' => (string) ($plugin->getAttribute('zip_root') ?? ''),
        ];
    }

    public function releaseFields(PluginRelease $release): array
    {
        $encapsulated = $release->getAttribute('encapsulated');

        return [
            'encapsulated' => $encapsulated instanceof Encapsulated ? $encapsulated->value : (string) ($encapsulated ?? Encapsulated::UNKNOWN->value),
            'version_folder' => $release->getAttribute('version_folder'),
            'zc_versions' => $release instanceof ZenCartPluginRelease
                ? $release->zcVersions->sortBy('position')->values()->map(fn ($version): array => ['id' => (int) $version->id, 'version' => (string) $version->version])->all()
                : [],
        ];
    }

    public function releaseRules(): array
    {
        return [
            'encapsulated' => ['nullable', Rule::enum(Encapsulated::class)],
            'zc_versions' => ['nullable', 'array'],
            'zc_versions.*' => ['integer'],
        ];
    }
}
