<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Search;

use Carbon\CarbonInterface;
use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Contracts\ResetsAfterFailedRun;
use Filagora\Ai\Services\EmbeddingBatchFailed;
use Filagora\Ai\Support\Embeddings;
use Filagora\Search\Contracts\BatchesDocuments;
use Filagora\Search\Contracts\GroupedSearchIndex;
use Filagora\Search\Contracts\SyncedExplicitly;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Code chunks as a vector-only index, filled only by `zencart:ingest-code` (saving a chunk never
 * embeds anything): no words are searched, only meaning, and only by the id, the
 * repo and the versions and release lines the chunk is in (so a question about one version finds
 * only code that is in it). The text is read back from the table.
 *
 * Which rows a run sends depends on what it is for: the ones not yet indexed (the default, and what a
 * plain reindex means), or the ones already indexed whose version list changed (a partial update, so
 * nothing is embedded again). A chunk is marked indexed as it is sent, and the mark is taken back if
 * the engine then rejects the run. A batch whose embedding fails is not sent at all.
 */
final class CodeChunkIndex implements BatchesDocuments, GroupedSearchIndex, ResetsAfterFailedRun, SyncedExplicitly
{
    public const NAME = 'code_chunks';

    public const VERSIONS = 'versions';

    private const EMBEDDING_TEXT_LIMIT = 6_000;

    public function __construct(private readonly bool $versionUpdates = false) {}

    /**
     * The same index, for sending the version lists of chunks already indexed.
     */
    public function forVersionUpdates(): self
    {
        return new self(true);
    }

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: [],
            filterable: ['repo', 'versions', 'generations'],
            vectorDimensions: Embeddings::dimensions(),
        );
    }

    public function model(): string
    {
        return CodeChunk::class;
    }

    public function shouldBeSearchable(Model $model): bool
    {
        return $model instanceof CodeChunk;
    }

    public function toDocument(Model $model): Document
    {
        /** @var Collection<int, Model> $single */
        $single = new Collection([$model]);

        return $this->toDocuments($single)[0];
    }

    public function toDocuments(Collection $models): array
    {
        $chunks = $models->filter(fn (Model $model): bool => $model instanceof CodeChunk)->values();
        $vectors = $this->vectors($chunks);

        CodeChunk::query()->whereIn('id', $chunks->modelKeys())->update(['indexed_at' => now(), 'versions_dirty' => false]);

        return $chunks->map(fn (CodeChunk $chunk, int $position): Document => new Document($chunk->getKey(), $this->fields($chunk), $vectors[$position] ?? null))->all();
    }

    public function groups(): array
    {
        return [self::VERSIONS];
    }

    public function toPartialDocument(Model $model, string $group): Document
    {
        assert($model instanceof CodeChunk);

        return new Document($model->getKey(), $this->fields($model));
    }

    /**
     * The chunks this run sends: those not yet indexed, or (for version updates) those indexed
     * whose version list changed.
     */
    public function query(): Builder
    {
        /** @var Builder<Model> $query */
        $query = $this->versionUpdates
            ? CodeChunk::query()->whereNotNull('indexed_at')->where('versions_dirty', true)
            : CodeChunk::query()->whereNull('indexed_at');

        return $query;
    }

    /**
     * Every chunk that has been sent, which is what the engine should hold.
     */
    public function expectedCount(): int
    {
        return CodeChunk::query()->whereNotNull('indexed_at')->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return new SearchResult([], 0);
    }

    public function resetAfterFailedRun(CarbonInterface $startedAt): void
    {
        CodeChunk::query()->where('indexed_at', '>=', $startedAt)->update(['indexed_at' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(CodeChunk $chunk): array
    {
        return [
            'repo' => $chunk->repo,
            'versions' => array_values($chunk->versions),
            'generations' => array_values($chunk->generations),
        ];
    }

    /**
     * One embedding call for the whole chunk of rows. A failure stops the run before anything is
     * sent: a vector-only index has no use for a chunk without one.
     *
     * @param  Collection<int, CodeChunk>  $chunks
     * @return array<int, list<float>>
     */
    private function vectors(Collection $chunks): array
    {
        if (Embeddings::dimensions() === null || $chunks->isEmpty()) {
            return [];
        }

        $texts = $chunks->map(fn (CodeChunk $chunk): string => mb_substr(trim(($chunk->symbol !== null ? $chunk->symbol."\n" : '').$chunk->body), 0, self::EMBEDDING_TEXT_LIMIT))->all();

        try {
            return app(EmbeddingProvider::class)->embedBatch($texts, 'code_chunk_index');
        } catch (Throwable $e) {
            throw new EmbeddingBatchFailed($e->getMessage(), $e);
        }
    }
}
