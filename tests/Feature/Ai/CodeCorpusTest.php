<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Ai\Services\EmbeddingBatchFailed;
use Filagora\Ai\Services\GroundingPrompt;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Models\CodeChunkLocation;
use Filagora\ZenCart\Ai\Retrieval\CodeRetrievalSource;
use Filagora\ZenCart\Ai\Search\CodeChunkIndex;
use Filagora\ZenCart\Tests\Support\FakeEmbeddings;
use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CodeCorpusTest extends TestCase
{
    use RefreshDatabase;

    private ArrayEngine $engine;

    private FakeEmbeddings $embeddings;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filagora-search.engine' => 'array',
            'filagora-ai.embeddings.enabled' => true,
            'filagora-zencart.code.enabled' => true,
            'filagora-zencart.code.tags' => ['v1.5.8a', 'v2.2.2', 'v3.0-dev' => 'master'],
        ]);
        $this->engine = new ArrayEngine;
        app(SearchEngines::class)->extend('array', fn () => $this->engine);
        $this->embeddings = new FakeEmbeddings;
        app()->instance(EmbeddingProvider::class, $this->embeddings);
        $this->engine->syncSettings((new CodeChunkIndex)->definition());
    }

    /**
     * @param  array<string, array{string, int, int}>  $places  version => [path, start, end]
     */
    private function chunk(string $symbol, array $places, bool $indexed = false): CodeChunk
    {
        $chunk = CodeChunk::query()->create([
            'content_hash' => hash('sha256', $symbol),
            'repo' => 'zencart',
            'kind' => 'function',
            'symbol' => $symbol,
            'body' => "function {$symbol}() {}",
            'versions' => array_keys($places),
            'generations' => array_values(array_unique(array_map(CodeChunk::generationFor(...), array_keys($places)))),
            'indexed_at' => $indexed ? now() : null,
        ]);

        foreach ($places as $version => [$path, $start, $end]) {
            CodeChunkLocation::query()->create(['code_chunk_id' => $chunk->id, 'version' => $version, 'path' => $path, 'start_line' => $start, 'end_line' => $end]);
        }

        return $chunk;
    }

    private function indexAll(): void
    {
        $this->embeddings->vectors = ['zen_get_thing' => [1.0, 0.0, 0.0], 'zen_new_thing' => [0.0, 1.0, 0.0]];
        app(Indexer::class)->reindex(new CodeChunkIndex);
    }

    public function test_the_index_is_vector_only_and_filters_on_repo_and_versions(): void
    {
        $definition = (new CodeChunkIndex)->definition();

        $this->assertSame([], $definition->searchable);
        $this->assertSame(['repo', 'versions', 'generations'], $definition->filterable);
        $this->assertSame(3, $definition->vectorDimensions);
    }

    public function test_a_batch_is_embedded_in_one_call_and_its_chunks_are_marked_indexed(): void
    {
        $this->chunk('a', ['v2.2.2' => ['a.php', 1, 2]]);
        $this->chunk('b', ['v2.2.2' => ['b.php', 1, 2]]);

        $this->indexAll();

        $this->assertSame([2], $this->embeddings->batches);
        $this->assertSame(0, (new CodeChunkIndex)->query()->count());
        $this->assertSame(2, (new CodeChunkIndex)->expectedCount());
    }

    public function test_a_batch_whose_embedding_fails_is_not_sent_and_not_marked(): void
    {
        $this->chunk('a', ['v2.2.2' => ['a.php', 1, 2]]);
        $this->embeddings->fails = true;

        try {
            $this->indexAll();
            $this->fail('The failure should have stopped the run.');
        } catch (EmbeddingBatchFailed) {
            // expected
        }

        $this->assertSame(1, (new CodeChunkIndex)->query()->count());
        $this->assertSame(0, $this->engine->stats((new CodeChunkIndex)->definition())->documents);
    }

    public function test_a_failed_run_gives_the_marks_back(): void
    {
        $this->chunk('a', ['v2.2.2' => ['a.php', 1, 2]]);
        $startedAt = now()->subSecond();
        $this->indexAll();

        (new CodeChunkIndex)->resetAfterFailedRun($startedAt);

        $this->assertSame(1, (new CodeChunkIndex)->query()->count());
    }

    public function test_only_chunks_already_indexed_with_a_changed_version_list_are_version_updates(): void
    {
        $this->chunk('pending', ['v2.2.2' => ['a.php', 1, 2]]);
        $clean = $this->chunk('clean', ['v2.2.2' => ['b.php', 1, 2]], indexed: true);
        $dirty = $this->chunk('dirty', ['v2.2.2' => ['c.php', 1, 2]], indexed: true);
        $dirty->forceFill(['versions_dirty' => true])->save();

        $updates = (new CodeChunkIndex)->forVersionUpdates()->query()->pluck('id')->all();

        $this->assertSame([$dirty->id], $updates);
        $this->assertNotContains($clean->id, $updates);
    }

    public function test_a_named_version_finds_code_in_it_and_cites_the_path_lines_and_a_link(): void
    {
        $this->chunk('zen_get_thing', ['v1.5.8a' => ['includes/functions/a.php', 10, 24], 'v2.2.2' => ['includes/functions/b.php', 30, 44]]);
        $this->indexAll();

        $items = app(CodeRetrievalSource::class)->retrieve(new RetrievalQuery('Zen Cart 1.5.8a gives a blank page', [1.0, 0.0, 0.0]));

        $this->assertCount(1, $items);
        $this->assertSame('includes/functions/a.php:10-24 @ v1.5.8a', $items[0]['title']);
        $this->assertSame('https://github.com/zencart/zencart/blob/v1.5.8a/includes/functions/a.php#L10-L24', $items[0]['url']);
        $this->assertSame('CODE', $items[0]['label']);
        $this->assertFalse($items[0]['version_assumed']);
        $this->assertArrayNotHasKey('note', $items[0]);
        $this->assertStringContainsString('function zen_get_thing', $items[0]['excerpt']);
    }

    public function test_with_no_version_named_the_latest_release_is_searched_not_the_dev_branch_and_the_prompt_is_told(): void
    {
        $this->chunk('zen_new_thing', ['v3.0-dev' => ['includes/x.php', 1, 5], 'v2.2.2' => ['includes/y.php', 8, 12]]);
        $this->indexAll();

        $items = app(CodeRetrievalSource::class)->retrieve(new RetrievalQuery('how do I add a payment module?', [0.0, 1.0, 0.0]));

        $this->assertSame('includes/y.php:8-12 @ v2.2.2', $items[0]['title']);
        $this->assertTrue($items[0]['version_assumed']);
        $this->assertStringContainsString('latest release (v2.2.2)', $items[0]['note']);
    }

    public function test_an_earlier_question_in_the_conversation_names_the_version(): void
    {
        $this->chunk('zen_get_thing', ['v1.5.8a' => ['includes/a.php', 1, 3], 'v2.2.2' => ['includes/b.php', 1, 3]]);
        $this->indexAll();

        $items = app(CodeRetrievalSource::class)->retrieve(new RetrievalQuery('why is it blank?', [1.0, 0.0, 0.0], null, 'I run Zen Cart 1.5.8a'));

        $this->assertSame('includes/a.php:1-3 @ v1.5.8a', $items[0]['title']);
    }

    public function test_a_line_without_the_exact_version_is_quoted_from_its_newest_version_holding_the_code(): void
    {
        config(['filagora-zencart.code.tags' => ['v1.5.7', 'v1.5.8a', 'v2.2.2']]);
        $this->chunk('zen_get_thing', ['v1.5.7' => ['includes/old.php', 1, 3], 'v1.5.8a' => ['includes/new.php', 5, 9]]);
        $this->indexAll();

        $items = app(CodeRetrievalSource::class)->retrieve(new RetrievalQuery('Zen Cart 1.5.9 breaks', [1.0, 0.0, 0.0]));

        $this->assertSame('includes/new.php:5-9 @ v1.5.8a', $items[0]['title']);
    }

    public function test_it_finds_nothing_while_off_or_while_embeddings_are_off(): void
    {
        $this->chunk('zen_get_thing', ['v2.2.2' => ['a.php', 1, 2]]);
        $this->indexAll();
        $query = new RetrievalQuery('Zen Cart 2.2.2', [1.0, 0.0, 0.0]);

        config(['filagora-zencart.code.enabled' => false]);
        $this->assertSame([], app(CodeRetrievalSource::class)->retrieve($query));

        config(['filagora-zencart.code.enabled' => true, 'filagora-ai.embeddings.enabled' => false]);
        $this->assertSame([], app(CodeRetrievalSource::class)->retrieve($query));
    }

    public function test_it_takes_as_many_pieces_as_asked_for(): void
    {
        foreach (['one', 'two', 'three'] as $name) {
            $this->chunk($name, ['v2.2.2' => ["{$name}.php", 1, 2]]);
        }
        $this->indexAll();

        $items = app(CodeRetrievalSource::class)->retrieve(new RetrievalQuery('Zen Cart 2.2.2', [0.3, 0.3, 0.3], null, null, ['limits' => ['code' => 2]]));

        $this->assertCount(2, $items);
        $this->assertSame(1, $this->engine->search((new CodeChunkIndex)->definition(), new SearchQuery(vector: [0.3, 0.3, 0.3], perPage: 1))->total > 0 ? 1 : 0);
    }

    public function test_the_rules_for_code_excerpts_reach_the_prompt(): void
    {
        $rules = app(GroundingPrompt::class)->rules();

        $this->assertStringContainsString('Excerpts marked CODE are Zen Cart source', $rules);
    }
}
