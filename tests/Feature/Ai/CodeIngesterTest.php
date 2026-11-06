<?php

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\ZenCart\Ai\Code\CodeIngester;
use Filagora\ZenCart\Ai\Models\CodeChunk;
use Filagora\ZenCart\Ai\Models\CodeChunkLocation;
use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class CodeIngesterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    #[DataProvider('paths')]
    public function test_only_english_php_outside_the_skipped_directories_is_included(string $path, bool $included): void
    {
        $this->assertSame($included, app(CodeIngester::class)->includes($path), $path);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'core function file' => ['includes/functions/functions_general.php', true],
            'admin page' => ['admin/categories.php', true],
            'english language file' => ['includes/languages/english/product_info.php', true],
            'english root language file' => ['includes/languages/english.php', true],
            'admin english language file' => ['admin/includes/languages/english/categories.php', true],
            'spanish language directory' => ['includes/languages/spanish/product_info.php', false],
            'spanish root language file' => ['includes/languages/spanish.php', false],
            'plugin language file' => ['zc_plugins/Demo/v1.0.0/admin/includes/languages/german/x.php', false],
            'vendor' => ['vendor/laravel/framework/src/x.php', false],
            'tests' => ['tests/unit/FooTest.php', false],
            'installer' => ['zc_install/includes/x.php', false],
            'extras' => ['extras/curl_postback.php', false],
            'not php' => ['includes/templates/template_default/css/style.css', false],
            'javascript' => ['includes/templates/jscript/jscript_a.js', false],
        ];
    }

    public function test_identical_code_in_two_versions_is_one_chunk_with_two_locations(): void
    {
        $shared = $this->checkout(['includes/functions/a.php' => $this->functionFile('zen_shared', 'return 1;')]);
        $later = $this->checkout([
            'includes/functions/moved/a.php' => $this->functionFile('zen_shared', 'return 1;'),
            'includes/functions/b.php' => $this->functionFile('zen_new', 'return 2;'),
        ]);

        $ingester = app(CodeIngester::class);
        $ingester->ingestVersion('zencart', 'v1.5.8', $shared);
        $result = $ingester->ingestVersion('zencart', 'v2.0.0', $later);

        $this->assertSame(1, $result['new_chunks']);
        $this->assertSame(2, CodeChunk::query()->count());

        $chunk = CodeChunk::query()->where('symbol', 'zen_shared')->firstOrFail();
        $this->assertSame(['v1.5.8', 'v2.0.0'], $chunk->versions);
        $this->assertSame(['1.5.x', '2.x'], $chunk->generations);
        $this->assertEqualsCanonicalizing(
            ['v1.5.8:includes/functions/a.php', 'v2.0.0:includes/functions/moved/a.php'],
            $chunk->locations->map(fn (CodeChunkLocation $location): string => "{$location->version}:{$location->path}")->all(),
        );

        $this->assertSame(['v2.0.0'], CodeChunk::query()->where('symbol', 'zen_new')->firstOrFail()->versions);
    }

    public function test_running_a_version_twice_changes_nothing(): void
    {
        $checkout = $this->checkout(['a.php' => $this->functionFile('zen_one', 'return 1;').$this->functionFile('zen_two', 'return 2;', false)]);
        $ingester = app(CodeIngester::class);

        $ingester->ingestVersion('zencart', 'v2.0.0', $checkout);
        $before = CodeChunk::query()->orderBy('id')->get(['id', 'content_hash', 'versions'])->toArray();
        $locationsBefore = CodeChunkLocation::query()->count();

        $again = $ingester->ingestVersion('zencart', 'v2.0.0', $checkout);

        $this->assertSame(0, $again['new_chunks']);
        $this->assertSame($before, CodeChunk::query()->orderBy('id')->get(['id', 'content_hash', 'versions'])->toArray());
        $this->assertSame($locationsBefore, CodeChunkLocation::query()->count());
    }

    public function test_code_removed_from_a_version_loses_that_version_and_an_orphan_is_deleted(): void
    {
        $first = $this->checkout(['a.php' => $this->functionFile('zen_keep', 'return 1;'), 'b.php' => $this->functionFile('zen_drop', 'return 2;')]);
        $ingester = app(CodeIngester::class);
        $ingester->ingestVersion('zencart', 'v2.0.0', $first);
        $ingester->ingestVersion('zencart', 'v2.0.1', $first);

        $second = $this->checkout(['a.php' => $this->functionFile('zen_keep', 'return 1;')]);
        $result = $ingester->ingestVersion('zencart', 'v2.0.1', $second);

        $this->assertSame(0, $result['removed_chunks']);
        $this->assertSame(['v2.0.0'], CodeChunk::query()->where('symbol', 'zen_drop')->firstOrFail()->versions);

        $result = $ingester->ingestVersion('zencart', 'v2.0.0', $second);

        $this->assertSame(1, $result['removed_chunks']);
        $this->assertNull(CodeChunk::query()->where('symbol', 'zen_drop')->first());
        $this->assertSame(['v2.0.0', 'v2.0.1'], CodeChunk::query()->where('symbol', 'zen_keep')->firstOrFail()->versions);
    }

    public function test_a_changed_function_replaces_the_old_chunk_for_that_version_only(): void
    {
        $ingester = app(CodeIngester::class);
        $old = $this->checkout(['a.php' => $this->functionFile('zen_calc', 'return 1;')]);
        $ingester->ingestVersion('zencart', 'v2.0.0', $old);
        $ingester->ingestVersion('zencart', 'v2.1.0', $old);

        $new = $this->checkout(['a.php' => $this->functionFile('zen_calc', 'return 99;')]);
        $ingester->ingestVersion('zencart', 'v2.1.0', $new);

        $chunks = CodeChunk::query()->where('symbol', 'zen_calc')->orderBy('id')->get();
        $this->assertCount(2, $chunks);
        $this->assertSame(['v2.0.0'], $chunks[0]->versions);
        $this->assertSame(['v2.1.0'], $chunks[1]->versions);
        $this->assertStringContainsString('return 99;', $chunks[1]->body);
    }

    public function test_an_already_indexed_chunk_whose_versions_change_is_flagged_for_a_partial_update(): void
    {
        $ingester = app(CodeIngester::class);
        $checkout = $this->checkout(['a.php' => $this->functionFile('zen_calc', 'return 1;')]);
        $ingester->ingestVersion('zencart', 'v2.0.0', $checkout);
        $chunk = CodeChunk::query()->firstOrFail();
        $this->assertFalse($chunk->versions_dirty);

        CodeChunk::query()->update(['indexed_at' => now()]);
        $ingester->ingestVersion('zencart', 'v2.0.1', $checkout);

        $chunk->refresh();
        $this->assertSame(['v2.0.0', 'v2.0.1'], $chunk->versions);
        $this->assertTrue($chunk->versions_dirty);
    }

    public function test_a_chunk_not_yet_indexed_is_not_flagged_when_its_versions_change(): void
    {
        $ingester = app(CodeIngester::class);
        $checkout = $this->checkout(['a.php' => $this->functionFile('zen_calc', 'return 1;')]);
        $ingester->ingestVersion('zencart', 'v2.0.0', $checkout);
        $ingester->ingestVersion('zencart', 'v2.0.1', $checkout);

        $this->assertFalse(CodeChunk::query()->firstOrFail()->versions_dirty);
    }

    public function test_skipped_paths_are_never_read(): void
    {
        $checkout = $this->checkout([
            'includes/ok.php' => $this->functionFile('zen_ok', 'return 1;'),
            'vendor/lib/x.php' => $this->functionFile('vendor_fn', 'return 1;'),
            'includes/languages/spanish/x.php' => $this->functionFile('spanish_fn', 'return 1;'),
            'zc_install/y.php' => $this->functionFile('install_fn', 'return 1;'),
        ]);

        app(CodeIngester::class)->ingestVersion('zencart', 'v2.0.0', $checkout);

        $this->assertSame(['zen_ok'], CodeChunk::query()->pluck('symbol')->all());
    }

    public function test_another_repo_with_the_same_code_and_version_label_is_kept_apart(): void
    {
        $checkout = $this->checkout(['a.php' => $this->functionFile('zen_calc', 'return 1;')]);
        $ingester = app(CodeIngester::class);

        $ingester->ingestVersion('zencart', 'v2.0.0', $checkout);
        $ingester->ingestVersion('lara-forum', 'v2.0.0', $checkout);
        // Re-ingesting one repo's version must not touch the other's.
        $ingester->ingestVersion('lara-forum', 'v2.0.0', $this->checkout(['b.php' => '<?php // nothing']));

        $this->assertSame(['zencart'], CodeChunk::query()->pluck('repo')->all());
        $this->assertSame(['v2.0.0'], CodeChunk::query()->firstOrFail()->versions);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function checkout(array $files): string
    {
        $directory = sys_get_temp_dir().'/code-ingester-'.Str::random(10);
        $this->directories[] = $directory;

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$directory}/{$path}"));
            File::put("{$directory}/{$path}", $contents);
        }

        return $directory;
    }

    private function functionFile(string $name, string $body, bool $withOpenTag = true): string
    {
        return ($withOpenTag ? "<?php\n" : '')."/**\n * The {$name} function.\n */\nfunction {$name}()\n{\n    {$body}\n}\n";
    }
}
