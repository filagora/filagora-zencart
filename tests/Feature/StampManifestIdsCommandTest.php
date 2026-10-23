<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginType;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Plugins\ManifestPluginId;
use Filagora\ZenCart\Tests\Support\Zips;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filagora-plugins.storage.disk' => 'local']);
});

function storedRelease(string $slug, array $entries, array $plugin = [], PluginStatus $status = PluginStatus::ACTIVE): PluginRelease
{
    $type = PluginType::firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin']);
    $made = Plugin::create($plugin + ['name' => ucfirst($slug), 'slug' => $slug, 'plugin_type_id' => $type->id, 'status' => $status]);
    $path = "plugins/{$slug}.zip";
    Storage::disk('local')->put($path, file_get_contents(Zips::path($entries)));

    return PluginRelease::create(['plugin_id' => $made->id, 'version' => '1.0.0', 'zip_path' => $path, 'status' => $status])->refresh();
}

function manifestId(PluginRelease $release): ?int
{
    return (new ManifestPluginId)->read(Storage::disk('local')->path($release->zip_path));
}

it('writes the right id into every live release that has the wrong one, and says what it did', function () {
    $wrong = storedRelease('wrong', Zips::encapsulated('wrong', 'v1.0.0', 0));
    $right = storedRelease('right', Zips::encapsulated('right', 'v1.0.0', 0), ['legacy_vbulletin_file_id' => 2431]);
    (new ManifestPluginId)->stamp(Storage::disk('local')->path($right->zip_path), 2431);

    $this->artisan('filagora:zencart:stamp-manifest-ids')
        ->expectsOutputToContain('Checked: 2')
        ->expectsOutputToContain('Already right: 1')
        ->expectsOutputToContain('Stamped: 1')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);

    expect(manifestId($wrong))->toBe($wrong->plugin_id)
        ->and(manifestId($right))->toBe(2431)
        ->and($wrong->refresh()->checksum_sha256)->toBe(hash_file('sha256', Storage::disk('local')->path($wrong->zip_path)));
});

it('changes nothing on a dry run, but counts what it would change', function () {
    $wrong = storedRelease('wrong', Zips::encapsulated('wrong', 'v1.0.0', 0));
    $before = md5_file(Storage::disk('local')->path($wrong->zip_path));
    $checksum = $wrong->checksum_sha256;

    $this->artisan('filagora:zencart:stamp-manifest-ids', ['--dry-run' => true])
        ->expectsOutputToContain('Stamped: 1')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(0);

    expect(md5_file(Storage::disk('local')->path($wrong->zip_path)))->toBe($before)->and($wrong->refresh()->checksum_sha256)->toBe($checksum);
});

it('limits itself to one plugin when asked', function () {
    $one = storedRelease('one', Zips::encapsulated('one', 'v1.0.0', 0));
    $two = storedRelease('two', Zips::encapsulated('two', 'v1.0.0', 0));

    $this->artisan('filagora:zencart:stamp-manifest-ids', ['--plugin' => 'two'])->expectsOutputToContain('Checked: 1')->assertExitCode(0);

    expect(manifestId($one))->toBe(0)->and(manifestId($two))->toBe($two->plugin_id);
});

it('leaves releases that are not live alone', function () {
    $waiting = storedRelease('waiting', Zips::encapsulated('waiting', 'v1.0.0', 0), status: PluginStatus::MODERATED);

    $this->artisan('filagora:zencart:stamp-manifest-ids')->expectsOutputToContain('Checked: 0')->assertExitCode(0);

    expect(manifestId($waiting))->toBe(0);
});

it('reports archives that are missing or declare no id, and leaves them', function () {
    $noId = storedRelease('noid', ['zc_plugins/noid/v1.0.0/manifest.php' => '<?php return [];']);
    $gone = storedRelease('gone', Zips::encapsulated('gone'));
    Storage::disk('local')->delete($gone->zip_path);
    $before = md5_file(Storage::disk('local')->path($noId->zip_path));

    $this->artisan('filagora:zencart:stamp-manifest-ids')
        ->expectsOutputToContain('Checked: 2')
        ->expectsOutputToContain('No manifest id: 1')
        ->expectsOutputToContain('Missing file: 1')
        ->expectsOutputToContain('Stamped: 0')
        ->assertExitCode(0);

    expect(md5_file(Storage::disk('local')->path($noId->zip_path)))->toBe($before);
});
