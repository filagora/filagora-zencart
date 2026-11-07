<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;

const NAVIGATION_MIGRATION = __DIR__.'/../../database/migrations/navigation/2026_11_07_000000_create_zencart_navigation_menus.php';

function runNavigationMigration(): void
{
    (require NAVIGATION_MIGRATION)->up();
}

function menu(string $name): array
{
    $row = DB::table(Tables::name('cms_navigations'))->where('name', $name)->first();

    return $row === null ? [] : json_decode((string) $row->items, true, flags: JSON_THROW_ON_ERROR);
}

function flatten(array $items): array
{
    $all = [];

    foreach ($items as $item) {
        $all[] = $item;
        $all = [...$all, ...flatten($item['children'] ?? [])];
    }

    return $all;
}

beforeEach(function () {
    DB::table(Tables::name('cms_navigations'))->delete();
    DB::table(Tables::name('cms_blocks'))->whereIn('slug', ['footer_github', 'footer_newsletter'])->delete();
});

it('creates the five menus and the two footer blocks', function () {
    runNavigationMigration();

    expect(DB::table(Tables::name('cms_navigations'))->orderBy('name')->pluck('name')->all())
        ->toBe(['footer_legal', 'footer_useful', 'forum_header', 'header', 'user_menu'])
        ->and(DB::table(Tables::name('cms_blocks'))->whereIn('slug', ['footer_github', 'footer_newsletter'])->orderBy('slug')->pluck('slug')->all())
        ->toBe(['footer_github', 'footer_newsletter']);
});

it('gives the header its four items and the member menu its eight', function () {
    runNavigationMigration();

    expect(array_column(menu('header'), 'label'))->toBe(['Community Help', 'Plugins', 'Services', 'Donate'])
        ->and(array_column(menu('user_menu'), 'label'))->toBe(['Account Settings', 'My Profile', 'Memberships & Billing', 'Messages', 'Members/Contact', 'Admin', 'AI Chat', 'Logout'])
        ->and(array_column(menu('forum_header'), 'label'))->toBe(['Last 24 Hours', 'Subscriptions', 'Unread Posts', 'Mark All Read']);
});

it('stores the footer blocks as reusable, published HTML blocks that belong to no page', function () {
    runNavigationMigration();

    $block = DB::table(Tables::name('cms_blocks'))->where('slug', 'footer_github')->first();

    expect($block->type)->toBe('raw_html')
        ->and((bool) $block->is_reusable)->toBeTrue()
        ->and((bool) $block->is_published)->toBeTrue()
        ->and((bool) $block->is_draft)->toBeFalse()
        ->and($block->cms_page_id)->toBeNull()
        ->and(json_decode((string) $block->data, true)['html'])->toContain('<section');
});

it('leaves a menu or block that exists exactly as it is', function () {
    DB::table(Tables::name('cms_navigations'))->insert(['name' => 'header', 'items' => '[{"label":"Staff made this"}]', 'created_at' => now(), 'updated_at' => now()]);
    DB::table(Tables::name('cms_blocks'))->insert(['slug' => 'footer_github', 'type' => 'raw_html', 'name' => 'Edited', 'sort_order' => 1, 'is_reusable' => true, 'is_published' => true, 'is_draft' => false, 'data' => '{"html":"<p>mine</p>"}', 'created_at' => now(), 'updated_at' => now()]);

    runNavigationMigration();

    expect(menu('header'))->toBe([['label' => 'Staff made this']])
        ->and(json_decode((string) DB::table(Tables::name('cms_blocks'))->where('slug', 'footer_github')->value('data'), true)['html'])->toBe('<p>mine</p>')
        ->and(DB::table(Tables::name('cms_navigations'))->count())->toBe(5);
});

it('does nothing the second time', function () {
    runNavigationMigration();
    $before = DB::table(Tables::name('cms_navigations'))->orderBy('name')->get()->toArray();

    runNavigationMigration();

    expect(DB::table(Tables::name('cms_navigations'))->orderBy('name')->get()->toArray())->toEqual($before)
        ->and(DB::table(Tables::name('cms_blocks'))->whereIn('slug', ['footer_github', 'footer_newsletter'])->count())->toBe(2);
});

it('rolls back without removing anything', function () {
    runNavigationMigration();

    (require NAVIGATION_MIGRATION)->down();

    expect(DB::table(Tables::name('cms_navigations'))->count())->toBe(5);
});

it('leaves out the links to the documentation when there is no address for it', function () {
    config(['filagora-zencart.docs.base_url' => null]);

    runNavigationMigration();

    $urls = array_column(flatten([...menu('header'), ...menu('footer_useful')]), 'url');

    expect(array_filter($urls, fn ($url) => str_contains((string) $url, '{docs}') || str_contains((string) $url, 'docs')))->toBe([])
        ->and(array_column(menu('header')[0]['children'], 'label'))->toBe(['Forum']);
});

it('fills in the documentation address when there is one', function () {
    config(['filagora-zencart.docs.base_url' => 'https://docs.example.test/']);

    runNavigationMigration();

    $children = menu('header')[0]['children'];

    expect(array_column($children, 'url'))->toBe(['/forums', 'https://docs.example.test/user', 'https://docs.example.test/dev'])
        ->and(array_column(flatten(menu('footer_useful')), 'url'))->toContain('https://docs.example.test/user/template/demos/');
});

it('uses only link kinds, pages and actions the profile knows', function () {
    config(['filagora-zencart.docs.base_url' => 'https://docs.example.test']);
    runNavigationMigration();
    $problems = [];

    foreach (['header', 'user_menu', 'forum_header', 'footer_useful', 'footer_legal'] as $name) {
        foreach (flatten(menu($name)) as $item) {
            $where = "{$name}: {$item['label']}";
            $known = match ($item['type'] ?? 'url') {
                'app_page' => array_key_exists($item['reference'] ?? '', config('filagora-zencart.cms.app_pages')),
                'legal_page' => array_key_exists($item['reference'] ?? '', config('filagora-zencart.cms.legal_pages')),
                'action' => array_key_exists($item['reference'] ?? '', config('filagora-zencart.cms.actions')),
                'url' => is_string($item['url'] ?? null) && $item['url'] !== '',
                default => false,
            };

            if (! $known) {
                $problems[] = $where;
            }
        }
    }

    expect($problems)->toBe([]);
});

it('keeps the member menu in the entitlement format, with no old role lists', function () {
    runNavigationMigration();

    foreach (menu('user_menu') as $item) {
        expect($item)->not->toHaveKey('roles')->and($item)->toHaveKey('entitlements');
    }

    expect(collect(menu('user_menu'))->firstWhere('label', 'Admin')['entitlements'])->toBe(['operator.dashboard.access'])
        ->and(collect(menu('user_menu'))->firstWhere('label', 'AI Chat')['entitlements'])->toBe(['ai_chat.access']);
});
