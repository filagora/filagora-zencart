<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Console;

use Filagora\ZenCart\Ai\Models\DocPage;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports pages from the documentation site's content/user and
 * content/dev folders into the doc_pages table. This only populates the
 * table; it deliberately does not index or embed anything itself (model
 * events are off during the upsert loop below) - run
 * `filagora:search:reindex --index=doc_pages --sync-settings` afterward, so
 * batching, --limit and --batch-size all apply to the embedding too.
 *
 * Source resolution, in priority order:
 *   1. --path=            an existing local checkout, used as-is (fast, no
 *                          network - what a developer with the docs repo
 *                          already checked out locally would use)
 *   2. --repo=             a git URL to clone/refresh (see cloneOrRefreshRepo())
 *   3. filagora-zencart.docs.path      (ZENCART_DOCS_PATH)   same as #1
 *   4. filagora-zencart.docs.repo_url  (ZENCART_DOCS_REPO_URL) same as #2 -
 *      this is what production uses, since a server has no local checkout
 */
class DocsImportCommand extends Command
{
    private const SKIPPED_FILENAMES = ['_index.md', '_allpages.md'];

    protected $signature = 'docs:import
        {--path= : Path to an existing local zencart-docs checkout (default: config(filagora-zencart.docs.path))}
        {--repo= : Git URL to clone/refresh instead (default: config(filagora-zencart.docs.repo_url))}
        {--ref= : Git branch/tag to check out (default: config(filagora-zencart.docs.ref))}
        {--dry-run : Parse and report only, do not write doc_pages}
        {--clear-missing : Delete doc_pages rows whose source file no longer exists in this import}';

    protected $description = 'Import content/user and content/dev pages from zencart-docs (local checkout or git clone) into doc_pages.';

    public function handle(): int
    {
        $basePath = $this->resolveBasePath();
        if ($basePath === null) {
            return self::FAILURE;
        }

        $contentPath = $basePath.'/content';
        if (! is_dir($contentPath)) {
            $this->error("Not a directory: {$contentPath}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $files = [
            ...glob($contentPath.'/user/*.md') ?: [],
            ...$this->globRecursive($contentPath.'/user'),
            ...glob($contentPath.'/dev/*.md') ?: [],
            ...$this->globRecursive($contentPath.'/dev'),
        ];
        $files = array_values(array_unique($files));

        $this->line(sprintf('Found %s markdown files under %s/content/{user,dev}.', number_format(count($files)), $basePath));

        $imported = 0;
        $skipped = 0;
        $seenSourceFiles = [];

        foreach ($files as $file) {
            $relativePath = ltrim(str_replace($contentPath, '', $file), '/');
            $filename = basename($file);

            if (in_array($filename, self::SKIPPED_FILENAMES, true)) {
                $skipped++;

                continue;
            }

            $parsed = $this->parseDocFile($file);
            if ($parsed === null) {
                $this->warn("Skipping (no frontmatter): {$relativePath}");
                $skipped++;

                continue;
            }

            [$frontMatter, $body] = $parsed;

            if ($this->isTruthy($frontMatter['noindex'] ?? null)) {
                $skipped++;

                continue;
            }

            $title = trim((string) ($frontMatter['title'] ?? ''));
            if ($title === '' || trim($body) === '') {
                $skipped++;

                continue;
            }

            $urlPath = '/'.rtrim(preg_replace('/\.md$/', '', $relativePath), '/').'/';
            $seenSourceFiles[] = $relativePath;

            if ($dryRun) {
                $this->line("Would import: {$relativePath} -> {$urlPath}");
                $imported++;

                continue;
            }

            DocPage::withoutEvents(function () use ($relativePath, $urlPath, $title, $frontMatter, $body): void {
                DocPage::query()->updateOrCreate(
                    ['source_file' => $relativePath],
                    [
                        'url_path' => $urlPath,
                        'title' => $title,
                        'description' => $frontMatter['description'] ?? null,
                        'category' => $frontMatter['category'] ?? null,
                        'body' => trim($body),
                    ],
                );
            });
            $imported++;
        }

        $this->info(sprintf('Imported %s pages, skipped %s.', number_format($imported), number_format($skipped)));

        if ($this->option('clear-missing') && ! $dryRun) {
            // whereNotIn([]) compiles to `1 = 1` (Laravel's query grammar
            // special-cases an empty array as "not in an empty set", which
            // is trivially true for every row) - without this guard, a run
            // that imports zero pages (e.g. every file failing to parse)
            // would wipe the entire table instead of safely doing nothing.
            if ($seenSourceFiles === []) {
                $this->warn('Imported zero pages - skipping --clear-missing to avoid deleting every existing doc_pages row.');

                return self::SUCCESS;
            }

            $removed = DocPage::query()->whereNotIn('source_file', $seenSourceFiles)->delete();
            if ($removed > 0) {
                $this->info("Removed {$removed} doc_pages rows no longer present in this import.");
            }
        }

        if (! $dryRun && $imported > 0) {
            $this->line('Run `php artisan filagora:search:reindex --index=doc_pages --sync-settings` next to index these pages (and embed them, if embeddings are on).');
        }

        return self::SUCCESS;
    }

    private function resolveBasePath(): ?string
    {
        $explicitPath = trim((string) ($this->option('path') ?? ''));
        if ($explicitPath !== '') {
            return rtrim($explicitPath, '/');
        }

        $explicitRepo = trim((string) ($this->option('repo') ?? ''));
        if ($explicitRepo !== '') {
            return $this->cloneOrRefreshRepo($explicitRepo, $this->resolveRef());
        }

        $configuredPath = trim((string) config('filagora-zencart.docs.path'));
        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/');
        }

        $configuredRepo = trim((string) config('filagora-zencart.docs.repo_url'));
        if ($configuredRepo !== '') {
            return $this->cloneOrRefreshRepo($configuredRepo, $this->resolveRef());
        }

        $this->error('No docs source configured. Pass --path=/path/to/zencart-docs, --repo=<git url>, or set ZENCART_DOCS_PATH / ZENCART_DOCS_REPO_URL in .env.');

        return null;
    }

    private function resolveRef(): string
    {
        $ref = trim((string) ($this->option('ref') ?? ''));

        return $ref !== '' ? $ref : (string) config('filagora-zencart.docs.ref', 'master');
    }

    /**
     * Shallow, sparse (content/user + content/dev only) clone into a scratch
     * directory under storage/app - re-cloned fresh every run rather than
     * incrementally pulled, since this is a low-frequency maintenance
     * command, not a hot path, and a fresh clone sidesteps every "diverged/
     * detached HEAD/dirty tree" edge case an incremental pull could hit.
     */
    private function cloneOrRefreshRepo(string $repoUrl, string $ref): ?string
    {
        $targetDir = storage_path('app/zencart-docs-repo');

        if (is_dir($targetDir)) {
            $this->line("Removing previous clone at {$targetDir}...");
            (new Process(['rm', '-rf', $targetDir]))->mustRun();
        }

        $this->line("Cloning {$repoUrl} (ref: {$ref}) into {$targetDir}...");

        try {
            (new Process([
                'git', 'clone',
                '--depth', '1',
                '--branch', $ref,
                '--single-branch',
                '--filter=blob:none',
                '--sparse',
                $repoUrl,
                $targetDir,
            ]))->setTimeout(120)->mustRun();

            (new Process(['git', 'sparse-checkout', 'set', 'content/user', 'content/dev'], $targetDir))
                ->setTimeout(30)
                ->mustRun();
        } catch (ProcessFailedException $e) {
            $this->error('Failed to clone the docs repo: '.$e->getMessage());

            return null;
        }

        return rtrim($targetDir, '/');
    }

    /**
     * @return list<string>
     */
    private function globRecursive(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.md') ?: [];
        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            $files = [...$files, ...$this->globRecursive($subDir)];
        }

        return $files;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function parseDocFile(string $file): ?array
    {
        $contents = (string) file_get_contents($file);

        if (! preg_match('/^\s*---\r?\n(.*?)\r?\n---\r?\n(.*)$/s', $contents, $matches)) {
            return null;
        }

        try {
            $frontMatter = Yaml::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($frontMatter)) {
            return null;
        }

        return [$frontMatter, $matches[2]];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1'], true);
    }
}
