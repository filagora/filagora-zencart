<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Retrieval;

use Filagora\Ai\Contracts\RetrievalSource;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Ai\Services\ChatRetrieval;
use Filagora\Ai\Support\Embeddings;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Models\DocPage;
use Filagora\ZenCart\Ai\Search\DocPageIndex;
use Illuminate\Support\Str;

/**
 * The Zen Cart documentation as something the chat can look in: the pages closest in meaning to the
 * message. Every page is public, so nothing is left out for who is asking.
 */
final class DocsRetrievalSource implements RetrievalSource
{
    private const EXCERPT_LENGTH = 500;

    public function __construct(private readonly Search $search) {}

    public function key(): string
    {
        return 'docs';
    }

    public function retrieve(RetrievalQuery $query): array
    {
        if (Embeddings::dimensions() === null) {
            return [];
        }

        $limit = $query->limitFor($this->key(), ChatRetrieval::defaultLimit());
        $ids = array_map('intval', $this->search->search(DocPageIndex::NAME, new SearchQuery(vector: $query->vector, perPage: $limit))->ids());

        if ($ids === []) {
            return [];
        }

        $pages = DocPage::query()->whereIn('id', $ids)->get()->keyBy('id');
        $base = rtrim((string) config('filagora-zencart.docs.base_url'), '/');
        $items = [];

        foreach ($ids as $id) {
            $page = $pages->get($id);

            if ($page === null) {
                continue;
            }

            $items[] = [
                'type' => 'doc_page',
                'id' => $page->id,
                'title' => $page->title,
                'url' => $base.$page->url_path,
                'excerpt' => Str::limit((string) ($page->description ?: $page->body), self::EXCERPT_LENGTH),
            ];
        }

        return $items;
    }
}
