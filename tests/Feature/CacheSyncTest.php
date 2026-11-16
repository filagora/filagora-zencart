<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\ZenCart\CacheSync\CacheSyncClient;
use Filagora\ZenCart\CacheSync\PushVersioncheckCache;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Models\ZcVersion;
use Filagora\ZenCart\Plugins\ZenCartReleaseExtension;
use Filagora\ZenCart\VersionCheck\PayloadBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Route::get('/plugins/{plugin:slug}', fn () => 'plugin')->name('plugins.show');
});

/**
 * Switch sync on. Done after a test has built its plugin, because building one saves it, and a save
 * with sync on pushes (here, straight away: the queue is synchronous in tests).
 */
function syncOn(): void
{
    config([
        'filagora-zencart.cache_sync.enabled' => true,
        'filagora-zencart.cache_sync.base_url' => 'https://cache.example.test/',
        'filagora-zencart.cache_sync.auth_token' => 'shared-secret',
    ]);
}

it('puts the answer to the cache with the shared token and a user agent naming the plugin', function () {
    checkedPlugin();
    syncOn();
    Http::fake();

    (new PushVersioncheckCache(2431))->handle(app(PayloadBuilder::class), app(CacheSyncClient::class));

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://cache.example.test/versioncheck/2431'
            && $request->hasHeader('Authorization', 'Bearer shared-secret')
            && $request->hasHeader('X-Auth-Token', 'shared-secret')
            && $request->hasHeader('User-Agent', 'Plugin Cache Update [2431]')
            && json_decode($request['payload'], true)[0]['id'] === '2431';
    });
});

it('removes the entry when the plugin no longer answers', function () {
    checkedPlugin(['status' => PluginStatus::MODERATED->value]);
    syncOn();
    Http::fake();

    (new PushVersioncheckCache(2431))->handle(app(PayloadBuilder::class), app(CacheSyncClient::class));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://cache.example.test/versioncheck/2431');
});

it('sends nothing while switched off or missing its token', function () {
    checkedPlugin();
    syncOn();
    Http::fake();

    config(['filagora-zencart.cache_sync.enabled' => false]);
    (new PushVersioncheckCache(2431))->handle(app(PayloadBuilder::class), app(CacheSyncClient::class));

    config(['filagora-zencart.cache_sync.enabled' => true, 'filagora-zencart.cache_sync.auth_token' => '']);
    (new PushVersioncheckCache(2431))->handle(app(PayloadBuilder::class), app(CacheSyncClient::class));

    Http::assertNothingSent();
});

it('queues one push, however many saves of the plugin and its releases come together', function () {
    $plugin = checkedPlugin();
    syncOn();
    Queue::fake();

    $plugin->update(['name' => 'Renamed']);
    PluginRelease::query()->where('plugin_id', $plugin->id)->first()->update(['zip_size' => 1]);

    // The job is unique per plugin, so the second save adds nothing while the first waits.
    Queue::assertPushed(PushVersioncheckCache::class, 1);
    Queue::assertPushed(fn (PushVersioncheckCache $job): bool => $job->legacyPluginId === 2431);
});

it('pushes nothing for a plugin carts do not know by a library id, or when switched off', function () {
    $plugin = checkedPlugin(['legacy_vbulletin_file_id' => null]);
    syncOn();
    Queue::fake();

    $plugin->update(['name' => 'Renamed']);
    Queue::assertNotPushed(PushVersioncheckCache::class);

    config(['filagora-zencart.cache_sync.enabled' => false]);
    $plugin->update(['legacy_vbulletin_file_id' => 2431]);
    $plugin->update(['name' => 'Again']);

    Queue::assertNotPushed(PushVersioncheckCache::class);
});

it('re-pushes every library plugin, or the ones named', function () {
    checkedPlugin();
    Plugin::create(['name' => 'Second', 'slug' => 'second', 'legacy_vbulletin_file_id' => 17, 'plugin_type_id' => Plugin::query()->first()->plugin_type_id]);
    syncOn();
    Queue::fake();

    $this->artisan('filagora:zencart:push-versioncheck-cache')->assertExitCode(0);
    Queue::assertPushed(PushVersioncheckCache::class, 2);

    // Fresh locks and a fresh fake: only the plugin named is sent.
    Cache::flush();
    Queue::fake();

    $this->artisan('filagora:zencart:push-versioncheck-cache', ['--id' => [17]])->assertExitCode(0);
    Queue::assertPushed(PushVersioncheckCache::class, 1);
    Queue::assertPushed(fn (PushVersioncheckCache $job): bool => $job->legacyPluginId === 17);
});

it('refuses to re-push while sync is off', function () {

    $this->artisan('filagora:zencart:push-versioncheck-cache')->assertExitCode(1);
});

it('pushes once the Zen Cart versions of a new release are stored, which happens after the release is saved', function () {
    $plugin = checkedPlugin();
    $release = PluginRelease::query()->where('plugin_id', $plugin->id)->first();
    $version = ZcVersion::create(['version' => '2.3.0', 'is_active' => true, 'position' => 30]);
    syncOn();
    Queue::fake();

    app(ZenCartReleaseExtension::class)->created(
        $plugin,
        $release,
        new ReleaseInput('1.0.0', UploadedFile::fake()->create('plugin.zip', 1), 'Changes', false, ['zc_versions' => [$version->id]]),
    );

    Queue::assertPushed(fn (PushVersioncheckCache $job): bool => $job->legacyPluginId === 2431);
});

it('pushes every plugin that names a Zen Cart version when its label changes or it is deleted', function () {
    checkedPlugin();
    $version = ZcVersion::query()->where('version', '2.1.0')->first();
    syncOn();
    Queue::fake();

    $version->update(['position' => 11]);
    Queue::assertNotPushed(PushVersioncheckCache::class);

    $version->update(['version' => '2.1.1']);
    Queue::assertPushed(fn (PushVersioncheckCache $job): bool => $job->legacyPluginId === 2431);

    Cache::flush();
    Queue::fake();

    $version->delete();
    Queue::assertPushed(fn (PushVersioncheckCache $job): bool => $job->legacyPluginId === 2431);
});

it('lets a failed answer fail the job, so it is retried and then recorded as failed', function () {
    checkedPlugin();
    syncOn();
    Http::fake(['*' => Http::response('down', 500)]);

    expect(fn () => (new PushVersioncheckCache(2431))->handle(app(PayloadBuilder::class), app(CacheSyncClient::class)))
        ->toThrow(RequestException::class);
});

it('spreads queued re-pushes out so the cache is not asked for more a minute than it allows', function () {
    $type = checkedPlugin()->plugin_type_id;
    Plugin::create(['name' => 'Second', 'slug' => 'second', 'legacy_vbulletin_file_id' => 17, 'plugin_type_id' => $type]);
    Plugin::create(['name' => 'Third', 'slug' => 'third', 'legacy_vbulletin_file_id' => 18, 'plugin_type_id' => $type]);
    syncOn();
    Cache::flush();
    Queue::fake();

    $this->artisan('filagora:zencart:push-versioncheck-cache', ['--per-minute' => 30])->assertExitCode(0);

    $delays = Queue::pushed(PushVersioncheckCache::class)
        ->mapWithKeys(fn (PushVersioncheckCache $job): array => [$job->legacyPluginId => (int) now()->diffInSeconds($job->delay, false)])
        ->sortKeys();

    // 30 a minute is one every two seconds, in library id order (17, 18, 2431); the first goes at once.
    expect($delays->values()->map(fn (int $seconds): int => (int) round($seconds / 2) * 2)->all())->toBe([0, 2, 4]);
});

it('waits between sends when it sends in this process, and not before the first or with no limit', function () {
    $type = checkedPlugin()->plugin_type_id;
    Plugin::create(['name' => 'Second', 'slug' => 'second', 'legacy_vbulletin_file_id' => 17, 'plugin_type_id' => $type]);
    syncOn();
    Http::fake();
    Sleep::fake();

    $this->artisan('filagora:zencart:push-versioncheck-cache', ['--now' => true, '--per-minute' => 30])->assertExitCode(0);
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);

    Sleep::fake();
    $this->artisan('filagora:zencart:push-versioncheck-cache', ['--now' => true, '--per-minute' => 0])->assertExitCode(0);
    Sleep::assertNeverSlept();
});

it('refuses a negative rate', function () {
    checkedPlugin();
    syncOn();

    $this->artisan('filagora:zencart:push-versioncheck-cache', ['--per-minute' => -1])->assertExitCode(2);
});
