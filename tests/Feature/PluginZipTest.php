<?php

declare(strict_types=1);

use Filagora\ZenCart\Plugins\ManifestPluginId;
use Filagora\ZenCart\Plugins\ZipInspector;
use Filagora\ZenCart\Tests\Support\Zips;

it('finds the install folder and the literal version folder in the versioned layout', function () {
    $found = (new ZipInspector)->inspect(Zips::path(Zips::encapsulated('Skynet_Scanner', 'v1.0.1')));

    expect($found)->toBe(['root' => 'skynet_scanner', 'reason' => 'OK', 'version_folder' => 'v1.0.1']);
});

it('returns the version folder exactly as written, not lowercased or trimmed of its v', function (string $folder) {
    expect((new ZipInspector)->inspect(Zips::path(Zips::encapsulated('widget', $folder)))['version_folder'])->toBe($folder);
})->with(['V1.0.0', 'v1.2', '2.0.0-beta1', '1.0']);

it('finds a root from a manifest outside the zc_plugins folder', function () {
    $found = (new ZipInspector)->inspect(Zips::path(['widget/v1.0.0/manifest.php' => '<?php return [];', 'widget/v1.0.0/a.php' => 'x']));

    expect($found['root'])->toBe('widget')->and($found['version_folder'])->toBe('v1.0.0');
});

it('falls back to the single top folder when there is no manifest', function () {
    $found = (new ZipInspector)->inspect(Zips::path(['Widget/readme.txt' => 'x', 'Widget/src/a.php' => 'y', '__MACOSX/Widget/._readme.txt' => 'z']));

    expect($found)->toBe(['root' => 'widget', 'reason' => 'OK', 'version_folder' => null]);
});

it('does not guess when the archive names two folders', function () {
    $found = (new ZipInspector)->inspect(Zips::path(['one/readme.txt' => 'x', 'two/readme.txt' => 'y']));

    expect($found['root'])->toBeNull()->and($found['reason'])->toBe('The archive layout is ambiguous.');
});

it('does not guess when two manifests name two plugins, and never answers zc_plugins', function () {
    $entries = Zips::encapsulated('one') + Zips::encapsulated('two');
    $found = (new ZipInspector)->inspect(Zips::path($entries));

    expect($found['root'])->toBeNull()->and($found['reason'])->toBe('The archive layout is ambiguous.');
});

it('does not take zc_plugins for an install folder when no manifest names the plugin', function () {
    $found = (new ZipInspector)->inspect(Zips::path(['zc_plugins/widget/v1.0.0/readme.txt' => 'x']));

    expect($found['root'])->toBeNull()->and($found['reason'])->toContain('zc_plugins folder but no manifest');
});

it('says why when there is nothing to find', function () {
    $inspector = new ZipInspector;

    expect($inspector->inspect(Zips::path(['readme.txt' => 'x']))['reason'])->toBe('No install folder could be worked out from the archive.')
        ->and($inspector->inspect('/no/such/file.zip')['reason'])->toBe('The ZIP file is unreadable.');

    $notZip = tempnam(sys_get_temp_dir(), 'zc-not-');
    file_put_contents($notZip, 'not a zip');

    expect($inspector->inspect($notZip)['reason'])->toBe('The ZIP archive could not be opened.')
        ->and(is_file($notZip))->toBeTrue('an unreadable file is left where it was');
});

it('cleans a root the way the live site stores it', function () {
    expect((new ZipInspector)->normalizeRoot(' /Skynet_Scanner\\ '))->toBe('skynet_scanner')
        ->and((new ZipInspector)->normalizeRoot('  '))->toBeNull()
        ->and((new ZipInspector)->normalizeRoot(null))->toBeNull();
});

it('writes the plugin id into the one manifest that declares it, once', function () {
    $path = Zips::path(Zips::encapsulated('widget', 'v1.0.0', 0) + ['zc_plugins/widget/v1.0.0/other.php' => "'pluginId' => 5"]);
    $manifest = new ManifestPluginId;

    expect($manifest->read($path))->toBe(0)
        ->and($manifest->stamp($path, 2431))->toBeTrue()
        ->and($manifest->read($path))->toBe(2431)
        ->and($manifest->stamp($path, 2431))->toBeFalse('nothing to change the second time');

    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->getFromName('zc_plugins/widget/v1.0.0/other.php'))->toBe("'pluginId' => 5");
});

it('reads and writes the id with either kind of quote, and a negative placeholder', function () {
    $manifest = new ManifestPluginId;

    foreach (["'pluginId' => -1", '"pluginId" => 77', "'pluginId'=>0"] as $line) {
        $path = Zips::path(['zc_plugins/w/v1/manifest.php' => "<?php return [{$line}];"]);

        expect($manifest->stamp($path, 9))->toBeTrue()->and($manifest->read($path))->toBe(9);
    }
});

it('leaves an archive alone when it has no pluginId, or no single manifest, or no valid id to write', function () {
    $manifest = new ManifestPluginId;
    $none = Zips::path(['zc_plugins/w/v1/manifest.php' => '<?php return [];']);
    $two = Zips::path(Zips::encapsulated('one') + Zips::encapsulated('two'));
    $missing = Zips::path(['readme.txt' => 'x']);
    $before = [md5_file($none), md5_file($two), md5_file($missing)];

    expect($manifest->stamp($none, 5))->toBeFalse()
        ->and($manifest->stamp($two, 5))->toBeFalse()
        ->and($manifest->stamp($missing, 5))->toBeFalse()
        ->and($manifest->stamp(Zips::path(Zips::encapsulated('w')), 0))->toBeFalse()
        ->and($manifest->stamp('/no/such.zip', 5))->toBeFalse()
        ->and([md5_file($none), md5_file($two), md5_file($missing)])->toBe($before)
        ->and($manifest->read($none))->toBeNull()
        ->and($manifest->read($two))->toBeNull();
});

it('never writes an id of zero or less over a real one', function () {
    $manifest = new ManifestPluginId;
    $path = Zips::path(Zips::encapsulated('widget', 'v1.0.0', 2431));

    expect($manifest->stamp($path, 0))->toBeFalse()
        ->and($manifest->stamp($path, -5))->toBeFalse()
        ->and($manifest->read($path))->toBe(2431);
});
