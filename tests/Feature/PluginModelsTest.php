<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Support\Models;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Models\ZcVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function zcPlugin(array $attributes = []): Plugin
{
    $type = PluginType::query()->firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin']);

    return Plugin::create(array_merge(['name' => 'P'.uniqid(), 'slug' => 'p-'.uniqid(), 'plugin_type_id' => $type->id], $attributes))->refresh();
}

function zcRelease(Plugin $plugin, array $attributes = []): PluginRelease
{
    return PluginRelease::create(array_merge(['plugin_id' => $plugin->id, 'version' => '1.0.'.uniqid(), 'zip_path' => 'plugins/x/x.zip'], $attributes))->refresh();
}

it('swaps the plugin models for the subclasses that know the extra columns', function () {
    expect(Models::class('plugin'))->toBe(Plugin::class)
        ->and(Models::class('release'))->toBe(PluginRelease::class);
});

it('keeps the library id and the install folder, each unique across plugins', function () {
    $plugin = zcPlugin(['legacy_vbulletin_file_id' => '2431', 'zip_root' => 'skynet_scanner']);

    expect($plugin->legacy_vbulletin_file_id)->toBe(2431)
        ->and($plugin->zip_root)->toBe('skynet_scanner');

    expect(fn () => zcPlugin(['legacy_vbulletin_file_id' => 2431]))->toThrow(QueryException::class)
        ->and(fn () => zcPlugin(['zip_root' => 'skynet_scanner']))->toThrow(QueryException::class);
});

it('allows many plugins with no library id or folder', function () {
    zcPlugin();
    zcPlugin();

    expect(Plugin::whereNull('legacy_vbulletin_file_id')->whereNull('zip_root')->count())->toBe(2);
});

it('knows the number a cart asks the version check about: the library id, or its own id', function () {
    $old = zcPlugin(['legacy_vbulletin_file_id' => 2431]);
    $new = zcPlugin();

    expect($old->versionCheckId())->toBe(2431)
        ->and($new->versionCheckId())->toBe($new->id);
});

it('stores whether a release is encapsulated as an enum, unknown until told', function () {
    $plugin = zcPlugin();

    expect(zcRelease($plugin)->encapsulated)->toBe(Encapsulated::UNKNOWN);

    $release = zcRelease($plugin, ['encapsulated' => Encapsulated::YES, 'version_folder' => '1.2.0']);

    expect($release->encapsulated)->toBe(Encapsulated::YES)
        ->and($release->version_folder)->toBe('1.2.0')
        ->and(DB::table(Tables::name('plugin_releases'))->where('id', $release->id)->value('encapsulated'))->toBe('yes')
        ->and(Encapsulated::NO->label())->toBe('No');
});

it('says which Zen Cart versions a release works with, and which releases work with a version', function () {
    $v210 = ZcVersion::create(['version' => 'v2.1.0', 'position' => 1]);
    $v220 = ZcVersion::create(['version' => 'v2.2.0', 'position' => 2]);
    $release = zcRelease(zcPlugin());
    $other = zcRelease(zcPlugin());

    $release->zcVersions()->sync([$v210->id, $v220->id]);
    $other->zcVersions()->sync([$v220->id]);

    expect($release->zcVersions->pluck('version')->sort()->values()->all())->toBe(['v2.1.0', 'v2.2.0'])
        ->and($v220->releases()->count())->toBe(2)
        ->and($v210->releases->pluck('id')->all())->toBe([$release->id]);
});

it('keeps each version once per release', function () {
    $version = ZcVersion::create(['version' => 'v2.1.0']);
    $release = zcRelease(zcPlugin());
    $release->zcVersions()->attach($version->id);

    expect(fn () => $release->zcVersions()->attach($version->id))->toThrow(QueryException::class);
});

it('defaults a version to offered, at position zero, and refuses a duplicate', function () {
    $version = ZcVersion::create(['version' => 'v2.1.0'])->refresh();

    expect($version->is_active)->toBeTrue()
        ->and($version->position)->toBe(0)
        ->and(fn () => ZcVersion::create(['version' => 'v2.1.0']))->toThrow(QueryException::class);
});

it('removes the links, not the versions, with a release, and the links with a version', function () {
    $keep = ZcVersion::create(['version' => 'v2.1.0']);
    $drop = ZcVersion::create(['version' => 'v2.2.0']);
    $release = zcRelease(zcPlugin());
    $release->zcVersions()->sync([$keep->id, $drop->id]);

    $drop->delete();
    expect($release->zcVersions()->count())->toBe(1);

    $release->delete();
    expect(DB::table(Tables::name('plugin_release_zc_versions'))->count())->toBe(0)
        ->and(ZcVersion::count())->toBe(1);
});
