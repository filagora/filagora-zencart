<?php

declare(strict_types=1);

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Models\DocPage;
use Filagora\ZenCart\Ai\Retrieval\DocsRetrievalSource;
use Filagora\ZenCart\Ai\Search\DocPageIndex;
use Filagora\ZenCart\Tests\Support\FakeEmbeddings;

beforeEach(function () {
    $this->engine = new ArrayEngine;
    app(SearchEngines::class)->extend('array', fn () => $this->engine);
    config(['filagora-search.engine' => 'array', 'filagora-ai.chat.enabled' => true]);
    $this->embeddings = new FakeEmbeddings;
    app()->instance(EmbeddingProvider::class, $this->embeddings);

    $this->page = fn (string $title, string $body = 'Body.', ?string $category = 'order_total') => DocPage::query()->create([
        'source_file' => "user/{$title}.md",
        'url_path' => "/user/{$title}/",
        'title' => $title,
        'description' => null,
        'category' => $category,
        'body' => $body,
    ]);
});

function reindexDocs(): void
{
    test()->engine->syncSettings((new DocPageIndex)->definition());
    app(Indexer::class)->reindex(new DocPageIndex, batchSize: 2);
}

it('is a keyword index only while embeddings are off, and a vector index of the provider\'s length while on', function () {
    expect((new DocPageIndex)->definition()->vectorDimensions)->toBeNull();

    config(['filagora-ai.embeddings.enabled' => true]);

    expect((new DocPageIndex)->definition()->vectorDimensions)->toBe(3);
});

it('is registered, so saving a page indexes it', function () {
    $this->engine->syncSettings((new DocPageIndex)->definition());

    ($this->page)('coupons', 'Coupons give discounts.');

    expect($this->engine->search((new DocPageIndex)->definition(), new SearchQuery('discounts'))->total)->toBe(1);
});

it('embeds a whole chunk of pages in one call', function () {
    config(['filagora-ai.embeddings.enabled' => true]);
    ($this->page)('one');
    ($this->page)('two');
    ($this->page)('three');
    $this->embeddings->batches = [];

    reindexDocs();

    expect($this->embeddings->batches)->toBe([2, 1]);
});

it('indexes a page without a vector when embedding fails, and says so', function () {
    config(['filagora-ai.embeddings.enabled' => true]);
    ($this->page)('one');
    $this->embeddings->fails = true;
    $this->engine->syncSettings((new DocPageIndex)->definition());

    app(Indexer::class)->reindex(new DocPageIndex);

    $definition = (new DocPageIndex)->definition();
    expect($this->engine->search($definition, new SearchQuery(perPage: 10))->total)->toBe(1)
        ->and($this->engine->search($definition, new SearchQuery(vector: [1.0, 0.0, 0.0], perPage: 10))->total)->toBe(0);
});

it('answers the search page from the database when the engine cannot', function () {
    ($this->page)('coupons', 'Coupons give discounts.');
    ($this->page)('shipping', 'Rates.');

    $result = (new DocPageIndex)->fallback(new SearchQuery('discounts'));

    expect($result->total)->toBe(1)
        ->and((new DocPageIndex)->fallback(new SearchQuery(vector: [1.0, 0.0, 0.0]))->total)->toBe(0);
});

it('offers the chat the pages closest in meaning, with links to where they are published', function () {
    config(['filagora-ai.embeddings.enabled' => true, 'filagora-zencart.docs.base_url' => 'https://docs.example.test/']);
    ($this->page)('coupons', 'Coupons give discounts.');
    ($this->page)('shipping', 'Rates.');
    $this->embeddings->vectors = ['coupons' => [1.0, 0.0, 0.0], 'shipping' => [0.0, 1.0, 0.0]];
    reindexDocs();

    $items = app(DocsRetrievalSource::class)->retrieve(new RetrievalQuery('how do coupons work', [1.0, 0.1, 0.0], null, null, ['limits' => ['docs' => 1]]));

    expect($items)->toHaveCount(1)
        ->and($items[0]['type'])->toBe('doc_page')
        ->and($items[0]['title'])->toBe('coupons')
        ->and($items[0]['url'])->toBe('https://docs.example.test/user/coupons/')
        ->and($items[0]['excerpt'])->toBe('Coupons give discounts.');
});

it('offers the chat nothing while embeddings are off', function () {
    ($this->page)('coupons');

    expect(app(DocsRetrievalSource::class)->retrieve(new RetrievalQuery('coupons', [1.0, 0.0, 0.0])))->toBe([]);
});
