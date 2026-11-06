<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Search\CodeChunkIndex;
use Filagora\ZenCart\Tests\Support\FakeEmbeddings;
use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

class IngestCodeCommandTest extends TestCase
{
    use RefreshDatabase;

    private ArrayEngine $engine;

    private FakeEmbeddings $embeddings;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filagora-search.engine' => 'array', 'filagora-ai.embeddings.enabled' => true]);
        $this->engine = new ArrayEngine;
        app(SearchEngines::class)->extend('array', fn () => $this->engine);
        $this->embeddings = new FakeEmbeddings;
        app()->instance(EmbeddingProvider::class, $this->embeddings);
        $this->engine->syncSettings((new CodeChunkIndex)->definition());

        $this->checkout = sys_get_temp_dir().'/ingest-code-'.uniqid();
        File::ensureDirectoryExists($this->checkout.'/includes/functions');
        file_put_contents($this->checkout.'/includes/functions/functions_thing.php', "<?php\n\n/**\n * Does the thing.\n */\nfunction zen_do_the_thing(\$a)\n{\n    return \$a * 2;\n}\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkout);

        parent::tearDown();
    }

    private function ingest(string $tag, array $options = []): PendingCommand
    {
        return $this->artisan('zencart:ingest-code', ['--tag' => [$tag], '--path' => $this->checkout, '--sleep-ms' => 0] + $options);
    }

    private function found(string $version): int
    {
        return $this->engine->search((new CodeChunkIndex)->definition(), new SearchQuery(filter: new Equals('versions', $version), vector: [0.3, 0.3, 0.3], perPage: 50))->total;
    }

    public function test_it_ingests_a_version_embeds_what_is_new_and_indexes_it(): void
    {
        $this->ingest('v2.2.2')->expectsOutputToContain('new chunks')->expectsOutputToContain('Done')->assertExitCode(0);

        $chunk = CodeChunk::query()->where('symbol', 'zen_do_the_thing')->firstOrFail();
        $this->assertSame(['v2.2.2'], $chunk->versions);
        $this->assertNotNull($chunk->indexed_at);
        $this->assertSame(1, $this->found('v2.2.2'));
    }

    public function test_repeating_it_costs_nothing(): void
    {
        $this->ingest('v2.2.2')->assertExitCode(0);
        $batches = $this->embeddings->batchesCount();

        $this->ingest('v2.2.2')->expectsOutputToContain('Nothing to embed')->assertExitCode(0);

        $this->assertSame($batches, $this->embeddings->batchesCount());
    }

    public function test_the_same_code_in_another_version_is_not_embedded_again_but_its_version_list_is_updated(): void
    {
        $this->ingest('v2.2.1')->assertExitCode(0);
        $batches = $this->embeddings->batchesCount();

        $this->ingest('v2.2.2')->expectsOutputToContain('1 indexed chunks to update')->assertExitCode(0);

        $this->assertSame($batches, $this->embeddings->batchesCount());
        $this->assertSame(1, CodeChunk::query()->count());
        $this->assertSame(['v2.2.1', 'v2.2.2'], CodeChunk::query()->first()->versions);
        $this->assertFalse(CodeChunk::query()->first()->versions_dirty);
        $this->assertSame(1, $this->found('v2.2.2'));
        $this->assertSame(1, $this->found('v2.2.1'));
    }

    public function test_code_that_no_version_holds_any_more_leaves_the_index_too(): void
    {
        $this->ingest('v2.2.2')->assertExitCode(0);
        file_put_contents($this->checkout.'/includes/functions/functions_thing.php', "<?php\n\n/**\n * Changed.\n */\nfunction zen_do_another_thing(\$a)\n{\n    return \$a * 3;\n}\n");

        $this->ingest('v2.2.2')->assertExitCode(0);

        $this->assertSame(['zen_do_another_thing'], CodeChunk::query()->pluck('symbol')->all());
        $this->assertSame(1, $this->found('v2.2.2'));
    }

    public function test_a_failing_embedding_provider_stops_it_with_the_code_stored_and_nothing_marked_indexed(): void
    {
        $this->embeddings->fails = true;

        $this->ingest('v2.2.2')->expectsOutputToContain('nothing was sent')->assertExitCode(1);

        $this->assertSame(1, CodeChunk::query()->count());
        $this->assertNull(CodeChunk::query()->first()->indexed_at);

        $this->embeddings->fails = false;
        $this->ingest('v2.2.2', ['--embed-only' => true])->expectsOutputToContain('Done')->assertExitCode(0);

        $this->assertNotNull(CodeChunk::query()->first()->indexed_at);
    }

    public function test_no_embed_stores_the_code_only(): void
    {
        $this->ingest('v2.2.2', ['--no-embed' => true])->assertExitCode(0);

        $this->assertSame(1, CodeChunk::query()->count());
        $this->assertNull(CodeChunk::query()->first()->indexed_at);
        $this->assertSame(0, $this->embeddings->batchesCount());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->ingest('v2.2.2', ['--dry-run' => true])->expectsOutputToContain('would ingest')->assertExitCode(0);

        $this->assertSame(0, CodeChunk::query()->count());
    }

    public function test_it_refuses_a_label_or_ref_that_is_not_a_version(): void
    {
        $this->artisan('zencart:ingest-code', ['--tag' => ['master']])->expectsOutputToContain('does not look like a version label')->assertExitCode(1);
        $this->artisan('zencart:ingest-code', ['--tag' => ['v3.0-dev=--upload-pack=x']])->expectsOutputToContain('does not look like a tag or branch')->assertExitCode(1);
        $this->artisan('zencart:ingest-code', ['--tag' => ['v2.2.2', 'v2.2.1'], '--path' => $this->checkout])->expectsOutputToContain('--path needs exactly one --tag')->assertExitCode(1);
    }
}
