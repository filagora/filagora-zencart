<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\ZenCart\Models\PluginRelease;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // The host's pages, which the check links to.
    Route::get('/plugins/{plugin:slug}', fn () => 'plugin')->name('plugins.show');
    Route::get('/threads/{thread}', fn () => 'thread')->name('threads.show');
    RateLimiter::clear('legacy-plugin-check');
});

it('answers the query form with the one-element array carts read', function () {
    $plugin = checkedPlugin();

    $payload = $this->get('/plugin_check.php?do=versioncheck&id=2431')->assertOk()->assertJsonCount(1)->json(0);

    expect($payload['id'])->toBe('2431')
        ->and($payload['title'])->toBe('Skynet Scanner')
        ->and($payload['latest_plugin_version'])->toBe('v1.0.0')
        ->and($payload['filesize'])->toBe('49470')
        ->and($payload['last_contributed_by'])->toBe('skynettechnologiesus')
        ->and($payload['encapsulated'])->toBe('1')
        ->and($payload['zcversions'])->toBe(['v2.1.0', 'v2.2.0'])
        ->and($payload['zcversions_csv'])->toBe('v2.1.0, v2.2.0')
        ->and($payload['link'])->toBe(route('plugins.show', $plugin))
        ->and($payload['support_thread'])->toBe('')
        ->and($payload['latest_plugin_updated_date'])->toMatch('/^\d+$/')
        ->and($payload['latest_plugin_updated_date_formatted'])->toMatch('/^\d{2} \w{3} \d{4}$/');
});

it('answers the path forms too', function () {
    checkedPlugin();

    $this->get('/plugin_check.php/versioncheck/2431')->assertOk()->assertJsonPath('0.id', '2431');
    $this->get('/plugincheck/2431')->assertOk()->assertJsonPath('0.id', '2431');
    $this->get('/plugin_check.php/2431')->assertOk()->assertJsonPath('0.id', '2431');
});

it('needs no User-Agent and ignores whatever one names', function () {
    checkedPlugin();

    foreach ([null, 'Plugin Version Check [999] example.com', 'Ping Server', 'curl/8.0'] as $agent) {
        $request = $agent === null ? $this->withHeaders(['User-Agent' => '']) : $this->withHeader('User-Agent', $agent);

        $request->get('/plugin_check.php?do=versioncheck&id=2431')->assertOk()->assertJsonPath('0.id', '2431');
    }
});

it('prefers the version folder over the typed version', function () {
    checkedPlugin(release: ['version' => '1.0.0', 'version_folder' => 'v1_0_0']);

    $this->get('/plugincheck/2431')->assertJsonPath('0.latest_plugin_version', 'v1_0_0');
});

it('finds a plugin by its own id when no library id matches', function () {
    $plugin = checkedPlugin(['legacy_vbulletin_file_id' => null]);

    $this->get('/plugincheck/'.$plugin->id)->assertOk()->assertJsonPath('0.title', 'Skynet Scanner');
});

it('answers an empty array for an unknown id, an inactive plugin and a missing id', function () {
    checkedPlugin(['status' => PluginStatus::MODERATED->value]);

    $this->get('/plugincheck/2431')->assertOk()->assertExactJson([]);
    $this->get('/plugincheck/9999')->assertOk()->assertExactJson([]);
    $this->get('/plugin_check.php')->assertOk()->assertExactJson([]);
    $this->get('/plugin_check.php?do=other&id=2431')->assertOk()->assertExactJson([]);
});

it('answers the newest release', function () {
    $plugin = checkedPlugin();
    PluginRelease::create([
        'plugin_id' => $plugin->id, 'version' => '1.2.0', 'internal_version' => 2, 'zip_path' => 'plugins/skynet2.zip',
        'zip_filename' => 'skynet2.zip', 'zip_size' => 1000, 'status' => PluginStatus::ACTIVE->value,
    ]);

    $this->get('/plugincheck/2431')->assertJsonPath('0.latest_plugin_version', 'v1.2.0')->assertJsonPath('0.filesize', '1000');
});

it('holds answers in shared caches, empty ones too, unless the lifetime is 0', function () {
    checkedPlugin();

    $this->get('/plugincheck/2431')->assertHeader('Cache-Control', 'max-age=900, public, s-maxage=900');
    $this->get('/plugincheck/9999')->assertHeader('Cache-Control', 'max-age=900, public, s-maxage=900');

    config(['filagora-zencart.versioncheck.cache_ttl_seconds' => 0]);

    expect($this->get('/plugincheck/2431')->headers->get('Cache-Control'))->not->toContain('public');
});

it('sets no cookie, so shared caches will store it', function () {
    checkedPlugin();

    expect($this->get('/plugincheck/2431')->headers->getCookies())->toBe([]);
});

it('limits an address per plugin, whatever the User-Agent', function () {
    checkedPlugin();

    foreach (range(1, 20) as $i) {
        $this->withHeader('User-Agent', "agent {$i}")->get('/plugincheck/2431')->assertOk();
    }

    $this->withHeader('User-Agent', 'yet another')->get('/plugincheck/2431')->assertStatus(429);
});
