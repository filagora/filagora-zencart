<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Console;

use Filagora\Ai\Contracts\EmbeddingProvider;
use Filagora\Ai\Indexing\BackfillResult;
use Filagora\Ai\Indexing\BackfillStop;
use Filagora\Ai\Indexing\VectorBackfill;
use Filagora\Ai\Providers\AbstractHttpEmbeddingProvider;
use Filagora\Search\Indexing\Indexer;
use Filagora\ZenCart\Ai\Code\CodeIngester;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Search\CodeChunkIndex;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Loads the Zen Cart source into code_chunks and sends it to the `code_chunks` index.
 *
 * Two stages. Ingest reads each tag's PHP into the database: free, and safe to repeat. Embedding then
 * sends every chunk not yet indexed (identical code in several tags is embedded once) and updates the
 * version list of chunks already indexed without embedding them again. A tag already ingested costs
 * nothing to ingest again unless its code changed.
 */
class IngestCodeCommand extends Command
{
    private const REPO = 'zencart';

    /**
     * @var string
     */
    protected $signature = 'zencart:ingest-code
        {--tag=* : A version to ingest (repeat for several): a tag (v2.2.2), or label=ref for a branch (v3.0-dev=master). Default: every entry in filagora-zencart.code.tags}
        {--path= : An existing checkout of ONE tag (needs --tag naming it), instead of cloning}
        {--embed-only : Skip ingesting; embed and index what is already in the database}
        {--no-embed : Ingest only; embed later with --embed-only}
        {--batch-size=100 : Chunks embedded per request}
        {--sleep-ms=250 : Pause between batches, to keep the load off the server}
        {--dry-run : List what would be ingested and change nothing}';

    /**
     * @var string
     */
    protected $description = 'Load Zen Cart source code (by release tag) into the code_chunks table and its vector index.';

    /**
     * @var string
     */
    protected $help = <<<'HELP'
Run it with no options to ingest every configured tag and index the result. Repeating it is safe: ingest replaces what it holds for each tag, and only chunks whose content is new are embedded.

If embedding stops (the provider, or the search engine), the code is already stored: fix the cause and run it again with --embed-only.
HELP;

    public function handle(CodeIngester $ingester, VectorBackfill $backfill, Indexer $indexer, EmbeddingProvider $embeddings): int
    {
        $path = trim((string) $this->option('path'));
        $specs = array_values(array_filter((array) $this->option('tag')));

        if ($path !== '' && (count($specs) !== 1 || ! is_dir($path))) {
            $this->error('--path needs exactly one --tag naming the release the checkout is, and the path must exist.');

            return self::FAILURE;
        }

        if ($specs === []) {
            foreach ((array) config('filagora-zencart.code.tags') as $key => $value) {
                // ['v2.2.2', ...] tags, or ['v3.0-dev' => 'master', ...] label => ref
                $specs[] = is_int($key) ? (string) $value : "{$key}={$value}";
            }
        }

        if ($specs === []) {
            $this->error('No versions to ingest: pass --tag or set filagora-zencart.code.tags.');

            return self::FAILURE;
        }

        // label => ref. The label is what the code is filed under; it becomes a directory name that is
        // later deleted, and the ref is handed to git.
        $tags = [];

        foreach ($specs as $spec) {
            [$label, $ref] = array_pad(explode('=', (string) $spec, 2), 2, null);
            $ref ??= $label;

            if (preg_match('/^v?\d+\.\d+[A-Za-z0-9._-]*$/', $label) !== 1) {
                $this->error("\"{$label}\" does not look like a version label (v2.2.2, v3.0-dev).");

                return self::FAILURE;
            }

            if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $ref) !== 1 || str_contains($ref, '..')) {
                $this->error("\"{$ref}\" does not look like a tag or branch name.");

                return self::FAILURE;
            }

            $tags[$label] = $ref;
        }

        foreach (['batch-size', 'sleep-ms'] as $name) {
            if (! ctype_digit((string) $this->option($name)) || ($name === 'batch-size' && (int) $this->option($name) < 1)) {
                $this->error("--{$name} must be a whole number".($name === 'batch-size' ? ' of at least 1.' : '.'));

                return self::FAILURE;
            }
        }

        if (! $this->option('embed-only') && ! $this->ingest($ingester, $tags, $path)) {
            return self::FAILURE;
        }

        if ($this->option('dry-run') || $this->option('no-embed')) {
            return self::SUCCESS;
        }

        return $this->embed($backfill, $indexer, $embeddings);
    }

    /**
     * @param  array<string, string>  $tags  version label => tag or branch to read it from
     */
    private function ingest(CodeIngester $ingester, array $tags, string $path): bool
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach ($tags as $tag => $ref) {
            $tag = (string) $tag;
            $this->line("== {$tag}".($ref !== $tag ? " (from {$ref})" : '').' ['.CodeChunk::generationFor($tag).']');

            $checkout = $path !== '' ? $path : $this->clone($tag, $ref);

            if ($checkout === null) {
                return false;
            }

            try {
                if ($dryRun) {
                    $this->line(sprintf('  would ingest %s PHP files', number_format(count($ingester->files($checkout)))));

                    continue;
                }

                $result = $ingester->ingestVersion(self::REPO, $tag, $checkout, function (int $done, int $all): void {
                    if ($done === $all || $done % 500 === 0) {
                        $this->line(sprintf('  %s / %s files', number_format($done), number_format($all)));
                    }
                });

                $this->line(sprintf(
                    '  %s files, %s new chunks, %s no longer in any version',
                    number_format($result['files']),
                    number_format($result['new_chunks']),
                    number_format($result['removed_chunks']),
                ));
            } catch (Throwable $e) {
                report($e);
                $this->error("Ingest of {$tag} failed: ".$e->getMessage().'. Nothing was embedded. Run it again: repeating is safe.');

                return false;
            } finally {
                if ($path === '') {
                    (new Process(['rm', '-rf', $checkout]))->run();
                }
            }
        }

        return true;
    }

    private function clone(string $tag, string $ref): ?string
    {
        $target = storage_path('app/zencart-code/'.$tag);
        (new Process(['rm', '-rf', $target]))->mustRun();

        try {
            // `--branch` takes a tag or a branch; the ref was checked not to start with a dash, so git
            // cannot mistake it for an option.
            (new Process([
                'git', 'clone', '--depth', '1', '--single-branch', '--branch', $ref, '--',
                (string) config('filagora-zencart.code.repo_url'), $target,
            ]))->setTimeout(300)->mustRun();
        } catch (ProcessFailedException $e) {
            $this->error("Could not clone {$ref}: ".trim($e->getProcess()->getErrorOutput()));

            return null;
        }

        return $target;
    }

    private function embed(VectorBackfill $backfill, Indexer $indexer, EmbeddingProvider $embeddings): int
    {
        $index = new CodeChunkIndex;
        $versionUpdates = $index->forVersionUpdates();
        $pending = $index->query()->count();
        $dirty = $versionUpdates->query()->count();

        if ($pending === 0 && $dirty === 0) {
            $this->info('Nothing to embed: every chunk is already indexed.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s chunks to embed, %s indexed chunks to update with new versions.', number_format($pending), number_format($dirty)));

        $batchSize = (int) $this->option('batch-size');
        $result = $pending === 0
            ? new BackfillResult(BackfillStop::COMPLETED)
            : $backfill->run($index, 'code_chunk_index', null, $batchSize, null, (int) $this->option('sleep-ms'), 2000, function (int $sent) use ($pending, $batchSize): void {
                if ($sent % 1_000 < $batchSize || $sent === $pending) {
                    $this->line(sprintf('  %s / %s chunks embedded', number_format($sent), number_format($pending)));
                }
            });

        $updated = 0;

        if ($result->succeeded() && $dirty > 0) {
            try {
                $updated = $indexer->backfill($versionUpdates, CodeChunkIndex::VERSIONS, batchSize: 500);
                CodeChunk::query()->whereNotNull('indexed_at')->where('versions_dirty', true)->update(['versions_dirty' => false]);
            } catch (Throwable $e) {
                report($e);
                $this->error('The version lists could not be updated: '.$e->getMessage().'. The chunks are indexed; run again with --embed-only to retry the version lists.');

                return self::FAILURE;
            }
        }

        return $this->report($result, $updated, $embeddings);
    }

    private function report(BackfillResult $result, int $versionUpdates, EmbeddingProvider $embeddings): int
    {
        $again = 'The code is stored and chunks already indexed are kept. Fix the cause, then run: php artisan zencart:ingest-code --embed-only';

        if ($result->stop === BackfillStop::NOT_READY) {
            $this->error($result->message.' The code is stored; once that is fixed run it again with --embed-only.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %s chunks embedded, %s version lists updated, in %ds. %s tokens, about $%s.',
            $result->succeeded() ? 'Done' : 'Stopped',
            number_format($result->sent),
            number_format($versionUpdates),
            $result->seconds,
            number_format($result->tokens),
            number_format($result->costUsd, 4),
        ));

        match ($result->stop) {
            BackfillStop::EMBEDDING_FAILED => $this->error('The embedding provider failed on a batch ('.$this->explain($embeddings, $result->failure?->getPrevious() ?? $result->failure).'). Nothing from that batch was sent. '.$again),
            BackfillStop::REJECTED => $this->error(sprintf('The search engine rejected an indexing task: %s. The chunks sent this run are marked pending again. %s', $result->message, $again)),
            BackfillStop::STALLED => $this->error('The search engine had not caught up with the batches already sent in the time allowed, so the run was stopped before spending more on embeddings. The chunks sent this run are marked pending again. '.$again),
            BackfillStop::CRASHED => $this->error(sprintf('The run failed (%s): %s. %s', $result->failure ? class_basename($result->failure) : 'error', $result->message, $again)),
            default => null,
        };

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    private function explain(EmbeddingProvider $embeddings, ?Throwable $failure): string
    {
        if ($failure === null) {
            return 'no reason given';
        }

        if (! $embeddings instanceof AbstractHttpEmbeddingProvider) {
            return $failure->getMessage();
        }

        return $embeddings->describeFailure($failure).' ['.$failure->getMessage().']';
    }
}
