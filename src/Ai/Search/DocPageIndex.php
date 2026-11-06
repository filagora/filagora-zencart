<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Search;

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Support\Embeddings;
use Filagora\Search\Contracts\BatchesDocuments;
use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Filagora\ZenCart\Ai\Models\DocPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Documentation pages as search documents: found by their words for the search page, and by their
 * meaning for the chat. The vectors exist only while embeddings are switched on; a page whose
 * vector could not be made is indexed without one and found by its words alone.
 */
final class DocPageIndex implements BatchesDocuments, SearchIndex
{
    public const NAME = 'doc_pages';

    /** How much of a page is embedded: a long page's start says what it is about. */
    private const EMBEDDING_TEXT_LIMIT = 20_000;

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: ['title', 'description', 'category', 'body'],
            filterable: ['category'],
            vectorDimensions: Embeddings::dimensions(),
        );
    }

    public function model(): string
    {
        return DocPage::class;
    }

    public function shouldBeSearchable(Model $model): bool
    {
        return $model instanceof DocPage;
    }

    public function toDocument(Model $model): Document
    {
        assert($model instanceof DocPage);

        /** @var Collection<int, Model> $single */
        $single = new Collection([$model]);

        return $this->documents($single)[0];
    }

    public function toDocuments(Collection $models): array
    {
        return $this->documents($models);
    }

    public function query(): Builder
    {
        /** @var Builder<Model> $query */
        $query = DocPage::query();

        return $query;
    }

    public function expectedCount(): int
    {
        return DocPage::query()->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        if ($query->vector !== null) {
            return new SearchResult([], 0);
        }

        $pages = DocPage::query();

        foreach (preg_split('/\s+/', trim($query->text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $pages->where(fn (Builder $where) => $where->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('body', 'like', $like));
        }

        $total = (clone $pages)->count();
        $ids = $pages->orderBy('title')->forPage($query->page, $query->perPage)->pluck('id');

        return new SearchResult($ids->map(fn ($id): Hit => new Hit($id))->all(), $total);
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<Document>
     */
    private function documents(Collection $models): array
    {
        $pages = $models->filter(fn (Model $model): bool => $model instanceof DocPage)->values();
        $vectors = $this->vectors($pages);

        return $pages->map(fn (DocPage $page, int $position): Document => new Document($page->getKey(), [
            'title' => $page->title,
            'description' => (string) $page->description,
            'category' => (string) $page->category,
            'url_path' => $page->url_path,
            'body' => $page->body,
        ], $vectors[$position] ?? null))->all();
    }

    /**
     * One embedding call for the whole chunk; a failure leaves the chunk without vectors, logged,
     * rather than failing the index run.
     *
     * @param  Collection<int, DocPage>  $pages
     * @return array<int, list<float>>
     */
    private function vectors(Collection $pages): array
    {
        if (Embeddings::dimensions() === null || $pages->isEmpty()) {
            return [];
        }

        $texts = $pages->map(fn (DocPage $page): string => $this->embeddingText($page))->all();

        try {
            return app(EmbeddingProvider::class)->embedBatch($texts, 'doc_page_index');
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function embeddingText(DocPage $page): string
    {
        return mb_substr(trim($page->title."\n\n".($page->description ?? '')."\n\n".$page->body), 0, self::EMBEDDING_TEXT_LIMIT);
    }
}
