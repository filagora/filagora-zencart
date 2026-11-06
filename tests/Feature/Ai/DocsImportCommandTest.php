<?php

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\ZenCart\Ai\Models\DocPage;
use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

class DocsImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = sys_get_temp_dir().'/docs-import-test-'.uniqid();
        mkdir($this->fixturePath.'/content/user/order_total', 0755, true);
        mkdir($this->fixturePath.'/content/dev/plugins', 0755, true);

        $this->writeDocFile($this->fixturePath.'/content/user/order_total/coupons.md', <<<'MD'
            ---
            title: Coupons
            description: Price reductions for customers with a code
            category: order_total
            ---
            Coupons allow you to offer discounts.
            MD);

        $this->writeDocFile($this->fixturePath.'/content/dev/plugins/manifests.md', <<<'MD'
            ---
            title: Manifests
            category: plugins
            ---
            A manifest describes a plugin's files.
            MD);

        // Should be skipped: Hugo section-index page, not real content.
        $this->writeDocFile($this->fixturePath.'/content/user/_index.md', <<<'MD'
            ---
            title: Storeowner Docs
            ---
            Links to other pages.
            MD);

        // Should be skipped: explicit noindex.
        $this->writeDocFile($this->fixturePath.'/content/user/_allpages.md', <<<'MD'
            ---
            title: All Pages
            noindex: yes
            ---
            A generated list.
            MD);

        // Should be skipped: no frontmatter at all.
        $this->writeDocFile($this->fixturePath.'/content/user/order_total/no_frontmatter.md', 'Just a body, no frontmatter.');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->fixturePath);

        parent::tearDown();
    }

    public function test_import_creates_doc_pages_from_markdown_with_correct_url_paths(): void
    {
        $exitCode = Artisan::call('docs:import', ['--path' => $this->fixturePath]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, DocPage::query()->count());

        $coupons = DocPage::where('source_file', 'user/order_total/coupons.md')->firstOrFail();
        $this->assertSame('Coupons', $coupons->title);
        $this->assertSame('order_total', $coupons->category);
        $this->assertSame('/user/order_total/coupons/', $coupons->url_path);
        $this->assertStringContainsString('Coupons allow you to offer discounts.', $coupons->body);

        $manifests = DocPage::where('source_file', 'dev/plugins/manifests.md')->firstOrFail();
        $this->assertSame('/dev/plugins/manifests/', $manifests->url_path);
    }

    public function test_import_skips_index_allpages_noindex_and_frontmatter_less_files(): void
    {
        Artisan::call('docs:import', ['--path' => $this->fixturePath]);

        $this->assertFalse(DocPage::where('source_file', 'user/_index.md')->exists());
        $this->assertFalse(DocPage::where('source_file', 'user/_allpages.md')->exists());
        $this->assertFalse(DocPage::where('source_file', 'user/order_total/no_frontmatter.md')->exists());
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        Artisan::call('docs:import', ['--path' => $this->fixturePath, '--dry-run' => true]);

        $this->assertSame(0, DocPage::query()->count());
    }

    public function test_rerunning_the_import_updates_existing_rows_instead_of_duplicating(): void
    {
        Artisan::call('docs:import', ['--path' => $this->fixturePath]);
        $originalId = DocPage::where('source_file', 'user/order_total/coupons.md')->firstOrFail()->id;

        $this->writeDocFile($this->fixturePath.'/content/user/order_total/coupons.md', <<<'MD'
            ---
            title: Coupons (updated)
            category: order_total
            ---
            Updated body text.
            MD);

        Artisan::call('docs:import', ['--path' => $this->fixturePath]);

        $this->assertSame(2, DocPage::query()->count());
        $updated = DocPage::where('source_file', 'user/order_total/coupons.md')->firstOrFail();
        $this->assertSame($originalId, $updated->id);
        $this->assertSame('Coupons (updated)', $updated->title);
    }

    public function test_clear_missing_removes_rows_for_deleted_source_files(): void
    {
        Artisan::call('docs:import', ['--path' => $this->fixturePath]);
        $this->assertSame(2, DocPage::query()->count());

        unlink($this->fixturePath.'/content/dev/plugins/manifests.md');

        Artisan::call('docs:import', ['--path' => $this->fixturePath, '--clear-missing' => true]);

        $this->assertSame(1, DocPage::query()->count());
        $this->assertFalse(DocPage::where('source_file', 'dev/plugins/manifests.md')->exists());
    }

    public function test_clear_missing_does_not_wipe_existing_rows_when_zero_pages_import(): void
    {
        Artisan::call('docs:import', ['--path' => $this->fixturePath]);
        $this->assertSame(2, DocPage::query()->count());

        // Simulate every file failing to parse (e.g. the real incident this
        // guards against: a missing dependency made every frontmatter parse
        // throw) by pointing --clear-missing at an empty content tree.
        $emptyPath = sys_get_temp_dir().'/docs-import-empty-'.uniqid();
        mkdir($emptyPath.'/content/user', 0755, true);
        mkdir($emptyPath.'/content/dev', 0755, true);

        Artisan::call('docs:import', ['--path' => $emptyPath, '--clear-missing' => true]);

        $this->assertSame(2, DocPage::query()->count(), 'existing rows must survive a zero-import run even with --clear-missing');

        $this->deleteDirectory($emptyPath);
    }

    private function writeDocFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents."\n");
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;
            is_dir($itemPath) ? $this->deleteDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
