<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Search;

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Support\Embeddings;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\Search\Contracts\BatchesDocuments;
use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The live releases of live plugins as a vector-only index, so the chat can find a plugin by what it
 * does. Kept apart from the keyword index of the same releases: a vector index declares an embedder,
 * and the keyword index's settings should never depend on one.
 *
 * Releases are indexed as they are saved, like the keyword index; with embeddings off nothing is
 * indexed at all. A release whose vector could not be made is left out, logged, and picked up by the
 * next reindex: a vector-only index has no use for a document without one.
 */
final class PluginReleaseVectorIndex implements BatchesDocuments, SearchIndex
{
    public const NAME = 'plugin_release_vectors';

    private const EMBEDDING_TEXT_LIMIT = 8_000;

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: [],
            filterable: ['status'],
            vectorDimensions: Embeddings::dimensions(),
        );
    }

    public function model(): string
    {
        return Models::class('release');
    }

    public function shouldBeSearchable(Model $model): bool
    {
        if (Embeddings::dimensions() === null || ! $model instanceof PluginRelease || $model->status !== PluginStatus::ACTIVE) {
            return false;
        }

        $plugin = $model->plugin;

        return $plugin->status === PluginStatus::ACTIVE && $plugin->type->is_active;
    }

    public function toDocument(Model $model): Document
    {
        /** @var Collection<int, Model> $single */
        $single = new Collection([$model]);

        return $this->toDocuments($single)[0] ?? new Document($model->getKey(), ['status' => 'unembedded']);
    }

    public function toDocuments(Collection $models): array
    {
        $releases = $models->filter(fn (Model $model): bool => $model instanceof PluginRelease)->values();
        $releases->loadMissing('plugin');
        $vectors = $this->vectors($releases);
        $documents = [];

        foreach ($releases as $position => $release) {
            if (isset($vectors[$position])) {
                $documents[] = new Document($release->getKey(), ['status' => $release->status->value], $vectors[$position]);
            }
        }

        return $documents;
    }

    public function query(): Builder
    {
        /** @var Builder<Model> $query */
        $query = Models::class('release')::query()
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereHas('plugin', fn (Builder $plugin) => $plugin
                ->where('status', PluginStatus::ACTIVE->value)
                ->whereHas('type', fn (Builder $type) => $type->where('is_active', true)));

        return $query;
    }

    public function expectedCount(): int
    {
        return Embeddings::dimensions() === null ? 0 : $this->query()->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return new SearchResult([], 0);
    }

    /**
     * The text a release is found by: what the plugin is called and does, and what the release changed.
     */
    public static function embeddingText(PluginRelease $release): string
    {
        $plugin = $release->plugin;

        return mb_substr(trim(($plugin instanceof Plugin ? (string) $plugin->name."\n\n".(string) $plugin->description : '')."\n\n".(string) $release->changelog), 0, self::EMBEDDING_TEXT_LIMIT);
    }

    /**
     * One embedding call for the whole chunk of releases, keyed by position; empty if it failed.
     *
     * @param  Collection<int, PluginRelease>  $releases
     * @return array<int, list<float>>
     */
    private function vectors(Collection $releases): array
    {
        if (Embeddings::dimensions() === null || $releases->isEmpty()) {
            return [];
        }

        try {
            return app(EmbeddingProvider::class)->embedBatch($releases->map(fn (PluginRelease $release): string => self::embeddingText($release))->all(), 'plugin_release_index');
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
