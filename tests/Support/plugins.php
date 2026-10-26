<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginType;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Models\ZcVersion;
use Filagora\ZenCart\Tests\Support\TestUser;

/**
 * A live plugin the version check can answer for: library id 2431, one active release that works
 * with two Zen Cart versions. Attributes override the plugin's; `$release` overrides the release's.
 */
function checkedPlugin(array $plugin = [], array $release = []): Plugin
{
    $type = PluginType::query()->firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin']);
    $owner = TestUser::make('skynettechnologiesus');

    $plugin = Plugin::create(array_merge([
        'legacy_vbulletin_file_id' => 2431,
        'name' => 'Skynet Scanner',
        'slug' => 'skynet-scanner',
        'plugin_type_id' => $type->id,
        'owner_id' => $owner->id,
        'status' => PluginStatus::ACTIVE->value,
    ], $plugin));

    $created = PluginRelease::create(array_merge([
        'plugin_id' => $plugin->id,
        'uploaded_by_id' => $owner->id,
        'version' => '1.0.0',
        'internal_version' => 1,
        'zip_path' => 'plugins/skynet.zip',
        'zip_filename' => 'skynet.zip',
        'zip_size' => 49470,
        'status' => PluginStatus::ACTIVE->value,
        'encapsulated' => Encapsulated::YES->value,
    ], $release));

    $created->zcVersions()->sync([
        ZcVersion::create(['version' => '2.1.0', 'is_active' => true, 'position' => 10])->id,
        ZcVersion::create(['version' => '2.2.0', 'is_active' => true, 'position' => 20])->id,
    ]);

    return $plugin;
}
