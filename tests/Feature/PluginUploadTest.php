<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Releases;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\ZcVersion;
use Filagora\ZenCart\Plugins\ManifestPluginId;
use Filagora\ZenCart\Tests\Support\TestUser;
use Filagora\ZenCart\Tests\Support\Zips;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filagora-plugins.storage.disk' => 'local', 'filagora-plugins.mail.enabled' => false]);
});

function grantKey(TestUser $user, string $key): TestUser
{
    UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);

    return $user;
}

function uploader(bool $autoPublish = true): TestUser
{
    $user = TestUser::make('Ada');

    return $autoPublish ? grantKey($user, 'operator.plugin.auto_publish') : $user;
}

function uploadInput(array $entries, string $version = '1.0.0', array $extra = []): ReleaseInput
{
    return new ReleaseInput($version, Zips::upload($entries), 'Changes', false, $extra);
}

function submitNew(TestUser $user, ReleaseInput $input, string $name = 'Skynet Scanner')
{
    $type = PluginType::firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin']);

    return app(Releases::class)->submitPlugin($user, ['name' => $name, 'plugin_type_id' => $type->id], $input);
}

function refusedWithDetail(callable $callback): string
{
    try {
        $callback();
    } catch (ReleaseRefused $refused) {
        return $refused->getMessage();
    }

    throw new RuntimeException('Expected a refusal.');
}

function archiveOf($release): string
{
    return Storage::disk('local')->path($release->zip_path);
}

it('stores the encapsulated flag, version folder, install folder and Zen Cart versions of an upload', function () {
    $v210 = ZcVersion::create(['version' => 'v2.1.0']);
    $v220 = ZcVersion::create(['version' => 'v2.2.0']);

    $release = submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner', 'v1.0.0'), '1.0.0', ['encapsulated' => 'yes', 'zc_versions' => [$v210->id, $v220->id]]));

    expect($release->encapsulated)->toBe(Encapsulated::YES)
        ->and($release->version_folder)->toBe('v1.0.0')
        ->and($release->zcVersions->pluck('version')->sort()->values()->all())->toBe(['v2.1.0', 'v2.2.0'])
        ->and($release->plugin->zip_root)->toBe('skynet_scanner');
});

it('writes the plugin\'s id into the manifest when an author who may publish uploads, and records the changed file', function () {
    $release = submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']));
    $path = archiveOf($release);

    expect($release->status)->toBe(PluginStatus::ACTIVE)
        ->and((new ManifestPluginId)->read($path))->toBe($release->plugin->id)
        ->and($release->zip_size)->toBe(filesize($path))
        ->and($release->checksum_sha256)->toBe(hash_file('sha256', $path));
});

it('uses the old library id for the manifest when the plugin has one', function () {
    $author = uploader();
    $first = submitNew($author, uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']));
    Plugin::whereKey($first->plugin_id)->update(['legacy_vbulletin_file_id' => 2431]);

    $second = app(Releases::class)->submitRelease($author, Plugin::find($first->plugin_id), uploadInput(Zips::encapsulated('skynet_scanner', 'v1.1.0'), '1.1.0', ['encapsulated' => 'yes']));

    expect((new ManifestPluginId)->read(archiveOf($second)))->toBe(2431);
});

it('leaves the archive alone while the release waits for review, and stamps it on approval', function () {
    $release = submitNew(uploader(autoPublish: false), uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']));
    $path = archiveOf($release);
    $checksum = $release->checksum_sha256;

    expect($release->status)->toBe(PluginStatus::MODERATED)
        ->and((new ManifestPluginId)->read($path))->toBe(0);

    $moderator = grantKey(TestUser::make('Mo'), 'operator.plugin.moderate');
    app(Moderation::class)->moderateRelease($moderator, $release->plugin, $release, ModerationAction::APPROVE);

    expect((new ManifestPluginId)->read($path))->toBe($release->plugin->id)
        ->and($release->refresh()->checksum_sha256)->not->toBe($checksum)
        ->and($release->checksum_sha256)->toBe(hash_file('sha256', $path));
});

it('does not touch stored archives when stamping is switched off', function () {
    config(['filagora-zencart.plugins.stamp_manifest_id' => false]);

    $release = submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']));

    expect((new ManifestPluginId)->read(archiveOf($release)))->toBe(0);
});

it('leaves an archive with no pluginId in its manifest as it was uploaded', function () {
    $release = submitNew(uploader(), uploadInput(['zc_plugins/w/v1.0.0/manifest.php' => '<?php return [];'], '1.0.0', ['encapsulated' => 'yes']));

    expect($release->checksum_sha256)->toBe(hash_file('sha256', archiveOf($release)));
});

it('refuses a version that is not the version folder in the archive, and stores nothing', function () {
    $message = refusedWithDetail(fn () => submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner', 'v1.0.1'), '1.0.2', ['encapsulated' => 'yes'])));

    expect($message)->toContain('does not match the version folder')->toContain('1.0.2')->toContain('1.0.1')
        ->and(Plugin::count())->toBe(0);
});

it('accepts a version folder written differently from the typed version when they are the same version', function () {
    $release = submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner', 'V1.0.0'), 'v1.0.0', ['encapsulated' => 'yes']));

    expect($release->version_folder)->toBe('V1.0.0');
});

it('refuses an install folder another plugin already uses', function () {
    submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']), 'First');

    $message = refusedWithDetail(fn () => submitNew(uploader(), uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']), 'Second'));

    expect($message)->toContain('already used by another plugin')->and(Plugin::count())->toBe(1);
});

it('keeps a plugin\'s later releases in the install folder it already has', function () {
    $author = uploader();
    $first = submitNew($author, uploadInput(Zips::encapsulated('skynet_scanner'), '1.0.0', ['encapsulated' => 'yes']));

    $message = refusedWithDetail(fn () => app(Releases::class)->submitRelease($author, Plugin::find($first->plugin_id), uploadInput(Zips::encapsulated('other_folder', 'v1.1.0'), '1.1.0', ['encapsulated' => 'yes'])));

    expect($message)->toContain('keep the existing install folder "skynet_scanner"');
});

it('refuses an encapsulated archive whose layout is ambiguous', function () {
    $message = refusedWithDetail(fn () => submitNew(uploader(), uploadInput(Zips::encapsulated('one') + Zips::encapsulated('two'), '1.0.0', ['encapsulated' => 'yes'])));

    expect($message)->toContain('must resolve to one install folder')->toContain('ambiguous');
});

it('does not look inside an archive that is not encapsulated, or whose layout is not known', function (string $flag) {
    $release = submitNew(uploader(), uploadInput(['readme.txt' => 'x', 'other/a.php' => 'y'], '1.0.0', ['encapsulated' => $flag]));

    expect($release->encapsulated->value)->toBe($flag)
        ->and($release->version_folder)->toBeNull()
        ->and($release->plugin->zip_root)->toBeNull();
})->with(['no', 'unknown']);

it('takes an upload that says nothing about it as unknown', function () {
    $release = submitNew(uploader(), uploadInput(['readme.txt' => 'x']));

    expect($release->encapsulated)->toBe(Encapsulated::UNKNOWN);
});

it('refuses a flag that is not yes, no or unknown', function () {
    expect(refusedWithDetail(fn () => submitNew(uploader(), uploadInput(['readme.txt' => 'x'], '1.0.0', ['encapsulated' => 'maybe']))))->toContain('yes, no or unknown');
});

it('refuses a Zen Cart version that does not exist, and stores nothing', function () {
    $real = ZcVersion::create(['version' => 'v2.1.0']);

    $message = refusedWithDetail(fn () => submitNew(uploader(), uploadInput(['readme.txt' => 'x'], '1.0.0', ['zc_versions' => [$real->id, 999]])));

    expect($message)->toContain('does not exist')->and(Plugin::count())->toBe(0);
});

it('counts a Zen Cart version chosen twice once', function () {
    $v = ZcVersion::create(['version' => 'v2.1.0']);

    $release = submitNew(uploader(), uploadInput(['readme.txt' => 'x'], '1.0.0', ['zc_versions' => [$v->id, (string) $v->id]]));

    expect($release->zcVersions)->toHaveCount(1);
});
