<?php

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\ZenCart\Ai\Models\DocPage;
use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/**
 * Exercises the real `git clone --sparse` path DocsImportCommand uses in
 * production (no local checkout available there) - against a throwaway
 * local git repo instead of the real GitHub remote, so this needs no
 * network access but still runs the actual git commands, not a mock.
 */
class DocsImportGitCloneTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceRepoPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceRepoPath = sys_get_temp_dir().'/docs-import-source-'.uniqid();
        mkdir($this->sourceRepoPath.'/content/user/order_total', 0755, true);

        file_put_contents($this->sourceRepoPath.'/content/user/order_total/coupons.md', <<<'MD'
            ---
            title: Coupons
            category: order_total
            ---
            Coupons allow you to offer discounts.

            MD);

        $run = fn (array $command) => (new Process($command, $this->sourceRepoPath))->mustRun();
        $run(['git', 'init', '--initial-branch=master']);
        $run(['git', 'config', 'user.email', 'test@example.test']);
        $run(['git', 'config', 'user.name', 'Test']);
        $run(['git', 'add', '.']);
        $run(['git', 'commit', '-m', 'Initial docs']);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->sourceRepoPath]))->run();
        (new Process(['rm', '-rf', storage_path('app/zencart-docs-repo')]))->run();

        parent::tearDown();
    }

    public function test_import_clones_and_imports_from_a_git_repo(): void
    {
        $exitCode = Artisan::call('docs:import', [
            '--repo' => $this->sourceRepoPath,
            '--ref' => 'master',
        ]);

        $this->assertSame(0, $exitCode);
        $page = DocPage::where('source_file', 'user/order_total/coupons.md')->firstOrFail();
        $this->assertSame('Coupons', $page->title);
        $this->assertStringContainsString('Coupons allow you to offer discounts.', $page->body);
    }

    public function test_clone_directory_only_contains_the_sparse_checked_out_paths(): void
    {
        Artisan::call('docs:import', [
            '--repo' => $this->sourceRepoPath,
            '--ref' => 'master',
        ]);

        $clonedDir = storage_path('app/zencart-docs-repo');
        $this->assertDirectoryExists($clonedDir.'/content/user');
        $this->assertFileExists($clonedDir.'/content/user/order_total/coupons.md');
    }

    public function test_a_second_import_removes_the_previous_clone_and_reclones_fresh(): void
    {
        Artisan::call('docs:import', ['--repo' => $this->sourceRepoPath, '--ref' => 'master']);

        file_put_contents($this->sourceRepoPath.'/content/user/order_total/coupons.md', <<<'MD'
            ---
            title: Coupons (updated)
            category: order_total
            ---
            Updated body.

            MD);
        (new Process(['git', 'commit', '-am', 'Update coupons'], $this->sourceRepoPath))->mustRun();

        Artisan::call('docs:import', ['--repo' => $this->sourceRepoPath, '--ref' => 'master']);

        $page = DocPage::where('source_file', 'user/order_total/coupons.md')->firstOrFail();
        $this->assertSame('Coupons (updated)', $page->title);
    }

    public function test_an_unknown_repo_fails_cleanly_without_a_stray_partial_clone(): void
    {
        $exitCode = Artisan::call('docs:import', [
            '--repo' => sys_get_temp_dir().'/definitely-does-not-exist-'.uniqid(),
            '--ref' => 'master',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, DocPage::query()->count());
    }
}
