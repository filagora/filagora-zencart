<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Code;

use Filagora\Search\Indexing\Indexer;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Models\CodeChunkLocation;
use Filagora\ZenCart\Ai\Search\CodeChunkIndex;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads one version of a source tree into code_chunks, replacing whatever an
 * earlier run recorded for that version. It writes rows only; embedding and
 * indexing are `CodeChunkIndex`'s job (run by `zencart:ingest-code`).
 *
 * Content is stored once: a chunk identical to one already known (from this
 * run, another file, or another version) is not stored again, it just gains a
 * location. Re-running a version therefore costs nothing in embeddings unless
 * its code changed.
 */
class CodeIngester
{
    /** Directory names that never hold code worth answering from, at any depth. */
    private const SKIPPED_DIRECTORIES = [
        '.git', '.github', 'vendor', 'node_modules', 'tests', 'zc_install', 'extras', 'docs',
        'images', 'cache', 'logs', 'tmp',
    ];

    /** Bigger than this is generated data, not code. */
    private const MAX_FILE_BYTES = 400_000;

    private const FILES_PER_TRANSACTION = 25;

    private const ROWS_PER_INSERT = 200;

    public function __construct(private readonly CodeChunker $chunker, private readonly Indexer $indexer) {}

    /**
     * Whether a path (relative to the repository root, slash separated) is
     * code to ingest: PHP, outside the skipped directories, and not a
     * translation other than English.
     */
    public function includes(string $relativePath): bool
    {
        if (! str_ends_with($relativePath, '.php')) {
            return false;
        }

        $parts = explode('/', $relativePath);

        if (array_intersect(array_slice($parts, 0, -1), self::SKIPPED_DIRECTORIES) !== []) {
            return false;
        }

        // includes/languages/spanish/..., includes/languages/spanish.php
        if (preg_match('#(?:^|/)languages/([^/]+?)(?:\.php)?(?:/|$)#', $relativePath, $language) === 1) {
            return $language[1] === 'english';
        }

        return true;
    }

    /**
     * @return list<string> relative paths, sorted
     */
    public function files(string $basePath): array
    {
        $basePath = rtrim($basePath, '/');
        $skipped = self::SKIPPED_DIRECTORIES;

        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), $skipped, true),
        ));

        $paths = [];

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->isLink() || $file->getSize() > self::MAX_FILE_BYTES) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($basePath)), '/');

            if ($this->includes($relative)) {
                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  callable(int, int): void|null  $progress  called with files done and files in all
     * @return array{files: int, locations: int, new_chunks: int, removed_chunks: int}
     */
    public function ingestVersion(string $repo, string $version, string $basePath, ?callable $progress = null): array
    {
        $files = $this->files($basePath);

        // Everything this version held before is re-derived below, so a file
        // deleted or changed since the last run leaves nothing behind.
        $touched = CodeChunkLocation::query()
            ->where('version', $version)
            ->whereIn('code_chunk_id', CodeChunk::query()->where('repo', $repo)->select('id'))
            ->distinct()
            ->pluck('code_chunk_id')
            ->all();

        CodeChunkLocation::query()
            ->where('version', $version)
            ->whereIn('code_chunk_id', CodeChunk::query()->where('repo', $repo)->select('id'))
            ->delete();

        $touched = array_fill_keys($touched, true);
        $newChunks = 0;
        $locations = 0;

        foreach (array_chunk($files, self::FILES_PER_TRANSACTION) as $done => $batch) {
            [$chunks, $places] = $this->chunkFiles($repo, $basePath, $batch);

            DB::transaction(function () use ($chunks, $places, $version, &$touched, &$newChunks, &$locations): void {
                $now = now();

                foreach (array_chunk($chunks, self::ROWS_PER_INSERT, true) as $rows) {
                    $newChunks += CodeChunk::query()->insertOrIgnore(array_map(
                        fn (array $row): array => $row + ['versions' => '[]', 'generations' => '[]', 'created_at' => $now, 'updated_at' => $now],
                        array_values($rows),
                    ));
                }

                $ids = CodeChunk::query()->whereIn('content_hash', array_keys($chunks))->pluck('id', 'content_hash');
                $rows = [];

                foreach ($places as [$hash, $path, $start, $end]) {
                    $id = $ids[$hash];
                    $touched[$id] = true;
                    $rows[] = ['code_chunk_id' => $id, 'version' => $version, 'path' => $path, 'start_line' => $start, 'end_line' => $end];
                }

                foreach (array_chunk($rows, self::ROWS_PER_INSERT * 2) as $part) {
                    $locations += CodeChunkLocation::query()->insertOrIgnore($part);
                }
            });

            if ($progress !== null) {
                $progress(min(count($files), ($done + 1) * self::FILES_PER_TRANSACTION), count($files));
            }
        }

        $removed = $this->settleVersions(array_keys($touched));

        return ['files' => count($files), 'locations' => $locations, 'new_chunks' => $newChunks, 'removed_chunks' => $removed];
    }

    /**
     * @param  list<string>  $paths
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{0: string, 1: string, 2: int, 3: int}>}
     *                                                                                                             chunk rows by hash, and where each one was found
     */
    private function chunkFiles(string $repo, string $basePath, array $paths): array
    {
        $chunks = [];
        $places = [];

        foreach ($paths as $path) {
            $source = @file_get_contents(rtrim($basePath, '/').'/'.$path);

            if ($source === false) {
                continue;
            }

            foreach ($this->chunker->chunk($source, $path) as $chunk) {
                $hash = CodeChunk::hashFor($repo, $chunk['kind'], $chunk['symbol'], $chunk['body']);

                $chunks[$hash] ??= [
                    'content_hash' => $hash,
                    'repo' => $repo,
                    'kind' => $chunk['kind'],
                    'symbol' => $chunk['symbol'],
                    'body' => $chunk['body'],
                ];
                $places[] = [$hash, $path, $chunk['start_line'], $chunk['end_line']];
            }
        }

        return [$chunks, $places];
    }

    /**
     * Brings each touched chunk's version list in line with its locations,
     * flags the ones already in the index whose list changed, and deletes
     * those no version holds any more (from the index first).
     *
     * @param  list<int>  $chunkIds
     * @return int how many chunks were removed
     */
    private function settleVersions(array $chunkIds): int
    {
        $removed = 0;

        foreach (array_chunk($chunkIds, 500) as $ids) {
            $versionsById = CodeChunkLocation::query()
                ->whereIn('code_chunk_id', $ids)
                ->select('code_chunk_id', 'version')
                ->distinct()
                ->get()
                ->groupBy('code_chunk_id')
                ->map(fn ($rows): array => $rows->pluck('version')->all());

            $orphans = [];

            foreach (CodeChunk::query()->whereIn('id', $ids)->get() as $chunk) {
                $versions = $versionsById->get($chunk->id, []);
                usort($versions, 'strnatcmp');

                if ($versions === []) {
                    $orphans[] = $chunk;

                    continue;
                }

                if ($versions === $chunk->versions) {
                    continue;
                }

                $generations = array_values(array_unique(array_map(CodeChunk::generationFor(...), $versions)));

                CodeChunk::withoutEvents(fn () => $chunk->forceFill([
                    'versions' => $versions,
                    'generations' => $generations,
                    'versions_dirty' => $chunk->versions_dirty || $chunk->indexed_at !== null,
                ])->save());
            }

            if ($orphans !== []) {
                $removed += count($orphans);
                $this->deleteChunks($orphans);
            }
        }

        return $removed;
    }

    /**
     * @param  list<CodeChunk>  $chunks
     */
    private function deleteChunks(array $chunks): void
    {
        $index = new CodeChunkIndex;

        foreach ($chunks as $chunk) {
            if ($chunk->indexed_at !== null) {
                $this->indexer->forget($index, $chunk->getKey());
            }
        }

        CodeChunk::query()->whereIn('id', collect($chunks)->pluck('id'))->delete();
    }
}
