<?php

declare(strict_types=1);

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginType;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Retrieval\PluginsRetrievalSource;
use Filagora\ZenCart\Ai\Search\PluginReleaseVectorIndex;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Tests\Support\FakeEmbeddings;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['filagora-search.engine' => 'array', 'filagora-ai.embeddings.enabled' => true]);
    $this->engine = new ArrayEngine;
    app(SearchEngines::class)->extend('array', fn () => $this->engine);
    $this->embeddings = new FakeEmbeddings;
    app()->instance(EmbeddingProvider::class, $this->embeddings);
    $this->engine->syncSettings((new PluginReleaseVectorIndex)->definition());
    Route::get('/plugins/{plugin:slug}', fn () => 'ok')->name('plugins.show');
    app('router')->getRoutes()->refreshNameLookups();

    $this->live = function (string $name, string $changelog = 'Fixes things.', array $plugin = [], array $release = []): PluginRelease {
        $type = PluginType::query()->firstOrCreate(['slug' => 'plugin'], ['name' => 'Plugin', 'is_active' => true]);
        $created = Plugin::create(['name' => $name, 'slug' => strtolower($name), 'plugin_type_id' => $type->id, 'description' => "{$name} description"]);
        $created->forceFill($plugin + ['status' => PluginStatus::ACTIVE])->save();
        $made = PluginRelease::create(['plugin_id' => $created->id, 'version' => '1.0.0', 'zip_path' => 'plugins/x/x.zip', 'changelog' => $changelog]);
        $made->forceFill($release + ['status' => PluginStatus::ACTIVE])->save();

        return $made;
    };
});

it('is a vector-only index of the status, as long as the provider\'s vectors', function () {
    $definition = (new PluginReleaseVectorIndex)->definition();

    expect($definition->searchable)->toBe([])
        ->and($definition->filterable)->toBe(['status'])
        ->and($definition->vectorDimensions)->toBe(3);
});

it('indexes a release as it is saved, with a vector made from the plugin and the changelog', function () {
    $this->embeddings->vectors = ['Paypal' => [1.0, 0.0, 0.0]];

    ($this->live)('Paypal');

    $found = $this->engine->search((new PluginReleaseVectorIndex)->definition(), new SearchQuery(vector: [1.0, 0.0, 0.0], perPage: 5));

    expect($found->total)->toBe(1);
});

it('indexes nothing while embeddings are off', function () {
    config(['filagora-ai.embeddings.enabled' => false]);

    ($this->live)('Paypal');

    expect((new PluginReleaseVectorIndex)->expectedCount())->toBe(0)
        ->and($this->engine->stats((new PluginReleaseVectorIndex)->definition())->documents)->toBe(0);
});

it('leaves out releases that are not live, plugins that are not live and types that are not active', function () {
    ($this->live)('Live');
    ($this->live)('Pending', release: ['status' => PluginStatus::MODERATED]);
    ($this->live)('Archived', plugin: ['status' => PluginStatus::ARCHIVED]);

    expect((new PluginReleaseVectorIndex)->query()->count())->toBe(1);
});

it('embeds a chunk of releases in one call, and leaves a chunk whose embedding failed out', function () {
    config(['filagora-ai.embeddings.enabled' => false]);
    ($this->live)('One');
    ($this->live)('Two');
    config(['filagora-ai.embeddings.enabled' => true]);
    $this->embeddings->batches = [];

    app(Indexer::class)->reindex(new PluginReleaseVectorIndex);

    expect($this->embeddings->batches)->toBe([2]);

    $this->engine->flush((new PluginReleaseVectorIndex)->definition());
    $this->embeddings->fails = true;
    app(Indexer::class)->reindex(new PluginReleaseVectorIndex);

    expect($this->engine->stats((new PluginReleaseVectorIndex)->definition())->documents)->toBe(0);
});

it('offers the chat the plugins closest in meaning, as the plugin page with the changelog as the excerpt', function () {
    $this->embeddings->vectors = ['Paypal' => [1.0, 0.0, 0.0], 'Shipping' => [0.0, 1.0, 0.0]];
    ($this->live)('Paypal', 'Adds express checkout.');
    ($this->live)('Shipping', 'Adds rates.');

    $items = app(PluginsRetrievalSource::class)->retrieve(new RetrievalQuery('take paypal', [1.0, 0.1, 0.0], null, null, ['limits' => ['plugins' => 1]]));

    expect($items)->toHaveCount(1)
        ->and($items[0]['type'])->toBe('plugin_release')
        ->and($items[0]['title'])->toBe('Paypal')
        ->and($items[0]['url'])->toEndWith('/plugins/paypal')
        ->and($items[0]['excerpt'])->toBe('Adds express checkout.');
});

it('falls back to the plugin description when a release has no changelog', function () {
    $this->embeddings->vectors = ['Paypal' => [1.0, 0.0, 0.0]];
    ($this->live)('Paypal', '');

    $items = app(PluginsRetrievalSource::class)->retrieve(new RetrievalQuery('paypal', [1.0, 0.0, 0.0]));

    expect($items[0]['excerpt'])->toBe('Paypal description');
});

it('does not offer a release that stopped being live since it was indexed', function () {
    $this->embeddings->vectors = ['Paypal' => [1.0, 0.0, 0.0]];
    $release = ($this->live)('Paypal');
    PluginRelease::query()->whereKey($release->id)->update(['status' => PluginStatus::MODERATED->value]);

    expect(app(PluginsRetrievalSource::class)->retrieve(new RetrievalQuery('paypal', [1.0, 0.0, 0.0])))->toBe([]);
});

it('offers nothing while embeddings are off', function () {
    config(['filagora-ai.embeddings.enabled' => false]);

    expect(app(PluginsRetrievalSource::class)->retrieve(new RetrievalQuery('paypal', [1.0, 0.0, 0.0])))->toBe([]);
});
