<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Retrieval;

use Filagora\Ai\Contracts\RetrievalSource;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Ai\Support\Embeddings;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Code\CodeVersionSelection;
use Filagora\ZenCart\Ai\Code\ZenCartVersionDetector;
use Filagora\ZenCart\Ai\Code\ZenCartVersions;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Models\CodeChunkLocation;
use Filagora\ZenCart\Ai\Search\CodeChunkIndex;
use Illuminate\Support\Str;

/**
 * Zen Cart source code as something the chat can look in: the pieces closest in meaning to the
 * message, from the version the message is about. The version comes from the message, else from what
 * the person asked earlier in the conversation, else the latest release is assumed and the prompt says so.
 *
 * Off (`filagora-zencart.code.enabled`) until the index has been filled; while off nothing here
 * touches it.
 */
final class CodeRetrievalSource implements RetrievalSource
{
    /** Code is quoted at more length than prose: the lines are the answer. */
    private const EXCERPT_LENGTH = 1_500;

    /**
     * Rules for using excerpts marked CODE, added to the prompt wherever this source is registered.
     */
    public const RULES = <<<'RULES'
        Excerpts marked CODE are Zen Cart source at the version shown. For how Zen Cart actually
        behaves, trust CODE and documentation over forum threads, which are other members' experience
        and may be wrong or out of date. When you rely on code, say which version it is from and cite
        the file and lines with the excerpt's link. Never state a file, function or setting name that
        is not in the excerpts or that you are not certain exists.
        RULES;

    public function __construct(
        private readonly Search $search,
        private readonly ZenCartVersionDetector $detector,
        private readonly ZenCartVersions $versions,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filagora-zencart.code.enabled');
    }

    public function key(): string
    {
        return 'code';
    }

    public function retrieve(RetrievalQuery $query): array
    {
        if (! self::enabled() || Embeddings::dimensions() === null) {
            return [];
        }

        $selection = $this->detector->detect($query->message, (string) $query->versionHint) ?? $this->detector->assumed();

        if ($selection === null) {
            return [];
        }

        $limit = $query->limitFor($this->key(), max(1, (int) config('filagora-zencart.code.top_k', 4)));
        $ids = array_map('intval', $this->search->search(CodeChunkIndex::NAME, new SearchQuery(filter: $selection->filter(), vector: $query->vector, perPage: $limit))->ids());

        return $ids === [] ? [] : $this->items($ids, $selection);
    }

    /**
     * @param  list<int>  $ids  in the order the search ranked them
     * @return list<array<string, mixed>>
     */
    private function items(array $ids, CodeVersionSelection $selection): array
    {
        $chunks = CodeChunk::query()->whereIn('id', $ids)->get()->keyBy('id');
        $locations = CodeChunkLocation::query()
            ->whereIn('code_chunk_id', $ids)
            ->when($selection->version !== null, fn ($query) => $query->where('version', $selection->version))
            ->get()
            ->groupBy('code_chunk_id');
        $items = [];

        foreach ($ids as $id) {
            $chunk = $chunks->get($id);

            // The version the lines are quoted from: the one asked about, or for a whole line the newest
            // of its versions that holds this code.
            $location = $locations->get($id, collect())
                ->filter(fn (CodeChunkLocation $location): bool => CodeChunk::generationFor($location->version) === $selection->generation)
                ->sort(fn (CodeChunkLocation $a, CodeChunkLocation $b): int => strnatcmp($b->version, $a->version))
                ->first();

            if ($chunk === null || ! $location instanceof CodeChunkLocation) {
                continue;
            }

            $lines = $location->end_line > $location->start_line ? "{$location->start_line}-{$location->end_line}" : (string) $location->start_line;
            $item = [
                'type' => 'code',
                'label' => 'CODE',
                'id' => $chunk->id,
                'title' => "{$location->path}:{$lines} @ {$location->version}",
                'url' => $this->versions->blobUrl($location->version, $location->path, $location->start_line, $location->end_line),
                'excerpt' => Str::limit($chunk->body, self::EXCERPT_LENGTH),
                'version' => $location->version,
                'version_assumed' => $selection->assumed,
            ];

            if ($selection->assumed) {
                // The question did not say which Zen Cart it was about, so the newest was searched; the answer has to tell the reader.
                $item['note'] = "The member did not say which Zen Cart version they use, so the CODE excerpts are from the latest release ({$location->version}). Say so, and that other versions may differ.";
            }

            $items[] = $item;
        }

        return $items;
    }
}
