<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const CONTENT_MIGRATION = __DIR__.'/../../database/migrations/content/2026_11_07_100000_create_site_content.php';

function runContentMigration(?string $path = __DIR__.'/../Support/content', array $placeholders = ['docs' => 'https://docs.example.test/', 'site' => 'https://site.example.test', 'support_email' => 'help@example.test']): void
{
    config(['filagora-zencart.content.path' => $path, 'filagora-zencart.content.placeholders' => $placeholders]);

    (require CONTENT_MIGRATION)->up();
}

function blocksOf(string $slug): array
{
    $page = DB::table(Tables::name('cms_pages'))->where('slug', $slug)->first();

    return DB::table(Tables::name('cms_blocks'))->where('cms_page_id', $page->id)->orderBy('sort_order')->get()
        ->map(fn ($block) => ['name' => $block->name, 'type' => $block->type, 'published' => (bool) $block->is_published, 'draft' => (bool) $block->is_draft, 'data' => json_decode((string) $block->data, true)])->all();
}

beforeEach(function () {
    Storage::fake('public');
    DB::table(Tables::name('cms_blocks'))->delete();
    DB::table(Tables::name('cms_pages'))->delete();
    DB::table(Tables::name('cms_media_assets'))->delete();
});

it('does nothing when the site keeps no content directory', function () {
    runContentMigration(null);

    expect(DB::table(Tables::name('cms_pages'))->count())->toBe(0)
        ->and(DB::table(Tables::name('cms_media_assets'))->count())->toBe(0);
});

it('does nothing when the directory has no pages file', function () {
    runContentMigration(sys_get_temp_dir());

    expect(DB::table(Tables::name('cms_pages'))->count())->toBe(0);
});

it('creates the pages as published, with their routes and search text', function () {
    runContentMigration();

    $home = DB::table(Tables::name('cms_pages'))->where('slug', 'home')->first();

    expect(DB::table(Tables::name('cms_pages'))->orderBy('slug')->pluck('slug')->all())->toBe(['home', 'privacy'])
        ->and($home->custom_route)->toBe('/')
        ->and($home->status)->toBe('published')
        ->and($home->title)->toBe('Home')
        ->and($home->seo_title)->toBe('Welcome')
        ->and($home->published_at)->toBe('2026-08-13 17:02:28');
});

it('creates each page\'s live blocks in order, published and not drafts', function () {
    runContentMigration();

    $blocks = blocksOf('home');

    expect(array_column($blocks, 'name'))->toBe(['First', 'Second'])
        ->and(array_column($blocks, 'type'))->toBe(['cta_band', 'raw_html'])
        ->and(array_unique(array_column($blocks, 'published')))->toBe([true])
        ->and(array_unique(array_column($blocks, 'draft')))->toBe([false]);
});

it('fills in the environment\'s addresses wherever the placeholders are, however deep', function () {
    runContentMigration();

    $first = blocksOf('home')[0]['data'];

    expect($first['url'])->toBe('https://docs.example.test/user')
        ->and($first['links'][0]['url'])->toBe('https://docs.example.test/dev')
        ->and($first['links'][1]['url'])->toBe('https://example.test/x')
        ->and(blocksOf('home')[1]['data']['html'])->toContain('href="https://site.example.test/go"')
        ->and(DB::table(Tables::name('cms_pages'))->where('slug', 'home')->value('seo_description'))->toBe('Docs live at https://docs.example.test/start')
        ->and(blocksOf('privacy')[0]['data']['html'])->toContain('mailto:help@example.test');
});

it('makes a link to an address it was not given a #, and a mailto link plain text', function () {
    runContentMigration(placeholders: ['docs' => '', 'site' => 'https://site.example.test', 'support_email' => '']);

    expect(blocksOf('home')[0]['data']['url'])->toBe('#')
        ->and(blocksOf('home')[0]['data']['links'][0]['url'])->toBe('#')
        ->and(blocksOf('privacy')[0]['data']['html'])->toBe('<p>Ask us.</p>');
});

it('copies the pictures that ship to the public disk and records them', function () {
    runContentMigration();

    $asset = DB::table(Tables::name('cms_media_assets'))->where('path', 'cms-media/box.png')->first();

    expect(Storage::disk('public')->get('cms-media/box.png'))->toBe('PNGDATA')
        ->and($asset->disk)->toBe('public')
        ->and($asset->filename)->toBe('box.png')
        ->and($asset->mime_type)->toBe('image/png')
        ->and($asset->alt_text)->toBe('A box')
        ->and($asset->size)->toBe(7)
        ->and(json_decode((string) $asset->meta, true))->toBe(['extension' => 'png', 'width' => 10, 'height' => 20]);
});

it('records a picture it has no file for, without a size', function () {
    runContentMigration();

    $asset = DB::table(Tables::name('cms_media_assets'))->where('path', 'cms-media/missing.png')->first();

    expect($asset->size)->toBeNull()->and(Storage::disk('public')->exists('cms-media/missing.png'))->toBeFalse();
});

it('does not overwrite a picture that is already there', function () {
    Storage::disk('public')->put('cms-media/box.png', 'STAFF CHANGED THIS');

    runContentMigration();

    expect(Storage::disk('public')->get('cms-media/box.png'))->toBe('STAFF CHANGED THIS');
});

it('leaves a page that exists exactly as it is, blocks included', function () {
    $id = DB::table(Tables::name('cms_pages'))->insertGetId(['slug' => 'home', 'custom_route' => '/', 'title' => 'Edited by staff', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

    runContentMigration();

    expect(DB::table(Tables::name('cms_pages'))->where('id', $id)->value('title'))->toBe('Edited by staff')
        ->and(DB::table(Tables::name('cms_blocks'))->where('cms_page_id', $id)->count())->toBe(0)
        ->and(DB::table(Tables::name('cms_pages'))->count())->toBe(2);
});

it('leaves alone a page that answers one of the routes, whatever its slug', function () {
    DB::table(Tables::name('cms_pages'))->insert(['slug' => 'landing', 'custom_route' => '/privacy', 'title' => 'Theirs', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

    runContentMigration();

    expect(DB::table(Tables::name('cms_pages'))->where('slug', 'privacy')->exists())->toBeFalse()
        ->and(DB::table(Tables::name('cms_pages'))->count())->toBe(2);
});

it('does nothing the second time', function () {
    runContentMigration();
    $before = [DB::table(Tables::name('cms_pages'))->count(), DB::table(Tables::name('cms_blocks'))->count(), DB::table(Tables::name('cms_media_assets'))->count()];

    runContentMigration();

    expect([DB::table(Tables::name('cms_pages'))->count(), DB::table(Tables::name('cms_blocks'))->count(), DB::table(Tables::name('cms_media_assets'))->count()])->toBe($before)
        ->and($before)->toBe([2, 3, 2]);
});

it('rolls back without removing anything', function () {
    runContentMigration();

    (require CONTENT_MIGRATION)->down();

    expect(DB::table(Tables::name('cms_pages'))->count())->toBe(2);
});
