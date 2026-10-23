<?php

declare(strict_types=1);

use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Models\ZcVersion;
use Filagora\ZenCart\Plugins\LegacyPluginIdentifiers;
use Filagora\ZenCart\Plugins\ZcVersionCompatibility;
use Filagora\ZenCart\Plugins\ZenCartApiFields;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\Validator;

function livePluginWith(array $plugin = [], PluginStatus $status = PluginStatus::ACTIVE): Plugin
{
    $type = PluginType::firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin']);
    $made = Plugin::create($plugin + ['name' => 'P'.uniqid(), 'slug' => 'p-'.uniqid(), 'plugin_type_id' => $type->id, 'status' => $status])->refresh();
    liveRelease($made, '1.0.0', status: $status);

    return $made;
}

function liveRelease(Plugin $plugin, string $version, array $zc = [], PluginStatus $status = PluginStatus::ACTIVE): PluginRelease
{
    $release = PluginRelease::create(['plugin_id' => $plugin->id, 'version' => $version, 'zip_path' => 'plugins/'.uniqid().'.zip', 'status' => $status])->refresh();
    $release->zcVersions()->sync(array_map(fn (string $label): int => ZcVersion::firstOrCreate(['version' => $label])->id, $zc));

    return $release->refresh();
}

it('binds the Zen Cart answers for compatibility, API fields and plugin identifiers', function () {
    expect(app(Compatibility::class))->toBeInstanceOf(ZcVersionCompatibility::class)
        ->and(app(ApiFields::class))->toBeInstanceOf(ZenCartApiFields::class)
        ->and(app(PluginIdentifiers::class))->toBeInstanceOf(LegacyPluginIdentifiers::class);
});

it('lists a release\'s Zen Cart versions in the order staff set', function () {
    ZcVersion::create(['version' => 'v2.2.0', 'position' => 2]);
    ZcVersion::create(['version' => 'v2.1.0', 'position' => 1]);
    $release = liveRelease(livePluginWith(), '1.1.0', ['v2.2.0', 'v2.1.0']);

    expect((new ZcVersionCompatibility)->targets($release))->toBe(['v2.1.0', 'v2.2.0'])
        ->and((new ZcVersionCompatibility)->relations())->toBe(['zcVersions']);
});

it('matches a target whether or not either side has the leading v', function (string $stored, string $asked, bool $expected) {
    $release = liveRelease(livePluginWith(), '1.1.0', [$stored]);

    expect((new ZcVersionCompatibility)->supports($release, $asked))->toBe($expected);
})->with([
    'v-prefixed stored, bare asked' => ['v2.1.0', '2.1.0', true],
    'bare stored, v-prefixed asked' => ['2.1.0', 'v2.1.0', true],
    'capital V' => ['V2.1.0', '2.1.0', true],
    'other version' => ['v2.1.0', '2.2.0', false],
    'a prefix of it is not it' => ['v2.1.0', '2.1', false],
]);

it('treats a blank target as no restriction, and a release with no versions as matching no particular one', function () {
    $bare = liveRelease(livePluginWith(), '1.1.0');

    expect((new ZcVersionCompatibility)->supports($bare, ''))->toBeTrue()
        ->and((new ZcVersionCompatibility)->supports($bare, 'v'))->toBeTrue()
        ->and((new ZcVersionCompatibility)->supports($bare, '2.1.0'))->toBeFalse();
});

it('narrows a release query to a Zen Cart version, with either spelling stored', function () {
    $plugin = livePluginWith();
    $stored = liveRelease($plugin, '1.1.0', ['v2.1.0']);
    $bare = liveRelease($plugin, '1.2.0', ['2.1.0']);
    $other = liveRelease($plugin, '1.3.0', ['v2.2.0']);
    $none = liveRelease($plugin, '1.4.0');

    $query = PluginRelease::query()->where('plugin_id', $plugin->id);
    (new ZcVersionCompatibility)->restrict($query, 'v2.1.0');

    expect($query->pluck('id')->sort()->values()->all())->toBe([$stored->id, $bare->id]);

    $all = PluginRelease::query()->where('plugin_id', $plugin->id);
    (new ZcVersionCompatibility)->restrict($all, ' ');

    expect($all->count())->toBe(5)->and([$other->id, $none->id])->each->toBeInt();
});

it('picks the newest release that works with the version a cart runs', function () {
    $plugin = livePluginWith();
    liveRelease($plugin, '1.1.0', ['v2.1.0']);
    $newerForOther = liveRelease($plugin, '2.0.0', ['v2.2.0']);

    $releases = PluginRelease::query()->where('plugin_id', $plugin->id)->with('zcVersions')->get();

    expect(app(ReleaseSelection::class)->latest($releases, '2.1.0')->version)->toBe('1.1.0')
        ->and(app(ReleaseSelection::class)->latest($releases, '2.2.0')->id)->toBe($newerForOther->id)
        ->and(app(ReleaseSelection::class)->latest($releases, null)->id)->toBe($newerForOther->id)
        ->and(app(ReleaseSelection::class)->latest($releases, '1.5.7'))->toBeNull();
});

it('finds a plugin by slug, old library id, install folder in any case, or its own id, in that order', function () {
    $plugin = livePluginWith(['slug' => 'skynet', 'legacy_vbulletin_file_id' => 2431, 'zip_root' => 'skynet_scanner']);
    $identifiers = app(PluginIdentifiers::class);

    expect($identifiers->find(['slug' => 'skynet'], null)?->id)->toBe($plugin->id)
        ->and($identifiers->find(['legacy_vbulletin_file_id' => 2431], null)?->id)->toBe($plugin->id)
        ->and($identifiers->find(['legacy_vbulletin_file_id' => '2431'], null)?->id)->toBe($plugin->id)
        ->and($identifiers->find(['zip_root' => ' Skynet_Scanner '], null)?->id)->toBe($plugin->id)
        ->and($identifiers->find(['id' => $plugin->id], null)?->id)->toBe($plugin->id);

    $other = livePluginWith(['slug' => 'other']);

    expect($identifiers->find(['slug' => 'other', 'legacy_vbulletin_file_id' => 2431], null)?->id)->toBe($other->id, 'the slug wins')
        ->and($identifiers->find(['legacy_vbulletin_file_id' => 2431, 'id' => $other->id], null)?->id)->toBe($plugin->id, 'the library id beats the plugin id');
});

it('finds nothing for an unknown plugin, or one the viewer may not see', function () {
    $hidden = livePluginWith(['slug' => 'hidden', 'legacy_vbulletin_file_id' => 77, 'zip_root' => 'hidden_root'], PluginStatus::MODERATED);
    $identifiers = app(PluginIdentifiers::class);

    expect($identifiers->find(['slug' => 'nope'], null))->toBeNull()
        ->and($identifiers->find(['legacy_vbulletin_file_id' => 999], null))->toBeNull()
        ->and($identifiers->find(['zip_root' => 'missing'], null))->toBeNull()
        ->and($identifiers->find(['id' => 0], null))->toBeNull()
        ->and($identifiers->find(['slug' => 'hidden'], TestUser::make('Visitor')))->toBeNull()
        ->and($identifiers->find(['legacy_vbulletin_file_id' => 77], null))->toBeNull()
        ->and($identifiers->find(['zip_root' => 'hidden_root'], null))->toBeNull()
        ->and($hidden->id)->toBeInt();
});

it('says whether a row names a plugin at all', function () {
    $identifiers = app(PluginIdentifiers::class);

    expect($identifiers->identifies(['slug' => 'x']))->toBeTrue()
        ->and($identifiers->identifies(['legacy_vbulletin_file_id' => 5]))->toBeTrue()
        ->and($identifiers->identifies(['zip_root' => 'x']))->toBeTrue()
        ->and($identifiers->identifies(['id' => 5]))->toBeTrue()
        ->and($identifiers->identifies(['slug' => ' ', 'zip_root' => '/', 'id' => 0, 'legacy_vbulletin_file_id' => 0]))->toBeFalse()
        ->and($identifiers->identifies([]))->toBeFalse();
});

it('adds the Zen Cart fields to a plugin and a release in the API', function () {
    $plugin = livePluginWith(['legacy_vbulletin_file_id' => 2431, 'zip_root' => 'skynet_scanner']);
    ZcVersion::create(['version' => 'v2.2.0', 'position' => 2]);
    ZcVersion::create(['version' => 'v2.1.0', 'position' => 1]);
    $release = liveRelease($plugin, '1.1.0', ['v2.2.0', 'v2.1.0']);
    $release->forceFill(['encapsulated' => 'yes', 'version_folder' => 'v1.1.0'])->save();
    $fields = app(ApiFields::class);

    expect($fields->pluginFields($plugin))->toBe(['legacy_vbulletin_file_id' => 2431, 'zip_root' => 'skynet_scanner'])
        ->and($fields->releaseFields($release->refresh()))->toBe([
            'encapsulated' => 'yes',
            'version_folder' => 'v1.1.0',
            'zc_versions' => [['id' => ZcVersion::where('version', 'v2.1.0')->value('id'), 'version' => 'v2.1.0'], ['id' => ZcVersion::where('version', 'v2.2.0')->value('id'), 'version' => 'v2.2.0']],
        ]);

    $bare = livePluginWith();

    expect($fields->pluginFields($bare))->toBe(['legacy_vbulletin_file_id' => null, 'zip_root' => ''])
        ->and($fields->releaseFields($bare->releases->first())['encapsulated'])->toBe('unknown');
});

it('validates the extra fields of an upload', function (array $input, bool $valid) {
    $rules = app(ApiFields::class)->releaseRules();

    expect(Validator::make($input, $rules)->passes())->toBe($valid);
})->with([
    'nothing' => [[], true],
    'a known flag and ids' => [['encapsulated' => 'yes', 'zc_versions' => [1, 2]], true],
    'a null flag' => [['encapsulated' => null], true],
    'an unknown flag' => [['encapsulated' => 'maybe'], false],
    'versions not a list' => [['zc_versions' => 'v2.1.0'], false],
    'a version that is not an id' => [['zc_versions' => ['v2.1.0']], false],
]);
