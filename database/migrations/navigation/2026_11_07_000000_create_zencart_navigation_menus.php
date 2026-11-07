<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The site's menus and its two footer blocks, so a new site starts with the header, the member menu,
 * the forum bar and the footer the live site has. Their content is the live site's as of 7 Nov 2026.
 *
 * It only ever creates: a menu or block that already exists is left exactly as it is, so the live
 * database (whose staff edit these on the dashboard) and any site whose staff have changed them are
 * not touched. Rolling it back removes nothing, for the same reason.
 *
 * The links to the documentation are written `{docs}/...` and filled in from
 * `filagora-zencart.docs.base_url`; with none set those items are left out rather than made dead links.
 */
return new class extends Migration
{
    public function up(): void
    {
        $navigations = Tables::name('cms_navigations');
        $blocks = Tables::name('cms_blocks');

        if (! Schema::hasTable($navigations) || ! Schema::hasTable($blocks)) {
            return;
        }

        $now = now();
        $docs = rtrim((string) config('filagora-zencart.docs.base_url'), '/');

        foreach ($this->menus() as $name => $items) {
            if (DB::table($navigations)->where('name', $name)->exists()) {
                continue;
            }

            DB::table($navigations)->insert([
                'name' => $name,
                'items' => json_encode($this->withDocsAddress($items, $docs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($this->footerBlocks() as $slug => $block) {
            if (DB::table($blocks)->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table($blocks)->insert([
                'slug' => $slug,
                'type' => 'raw_html',
                'name' => $block['name'],
                'sort_order' => $block['sort_order'],
                'is_reusable' => true,
                'is_published' => true,
                'is_draft' => false,
                'data' => json_encode(['html' => $block['html']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Not reversible: removing the menus would also remove whatever staff have made of them.
     */
    public function down(): void {}

    /**
     * Fill in the documentation address, dropping the items that point at it when there is none.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withDocsAddress(array $items, string $docs): array
    {
        $kept = [];

        foreach ($items as $item) {
            $url = is_string($item['url'] ?? null) ? $item['url'] : '';

            if (str_starts_with($url, '{docs}')) {
                if ($docs === '') {
                    continue;
                }

                $item['url'] = $docs.substr($url, strlen('{docs}'));
            }

            if (is_array($item['children'] ?? null)) {
                $item['children'] = $this->withDocsAddress($item['children'], $docs);
            }

            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function menus(): array
    {
        return [
            'header' => [
                [
                    'label' => 'Community Help',
                    'url' => '/forums',
                    'target' => '_self',
                    'banner' => false,
                    'type' => 'url',
                    'children' => [
                        [
                            'label' => 'Forum',
                            'type' => 'url',
                            'url' => '/forums',
                            'target' => '_self',
                            'entitlements' => [],
                        ],
                        [
                            'label' => 'Store Owner Documentation',
                            'type' => 'url',
                            'url' => '{docs}/user',
                            'target' => '_blank',
                            'entitlements' => [],
                        ],
                        [
                            'label' => 'Developer Documentation',
                            'type' => 'url',
                            'url' => '{docs}/dev',
                            'target' => '_blank',
                            'entitlements' => [],
                        ],
                    ],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Plugins',
                    'url' => '/plugins',
                    'target' => '_self',
                    'banner' => false,
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Services',
                    'url' => '/services',
                    'target' => '_self',
                    'banner' => false,
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Donate',
                    'url' => '/donate',
                    'target' => '_self',
                    'banner' => false,
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
            ],
            'user_menu' => [
                [
                    'label' => 'Account Settings',
                    'type' => 'app_page',
                    'reference' => 'profile',
                    'entitlements' => [],
                ],
                [
                    'label' => 'My Profile',
                    'type' => 'app_page',
                    'reference' => 'my_profile',
                    'entitlements' => [],
                ],
                [
                    'label' => 'Memberships & Billing',
                    'type' => 'app_page',
                    'reference' => 'billing',
                    'entitlements' => [],
                ],
                [
                    'label' => 'Messages',
                    'type' => 'app_page',
                    'reference' => 'messages',
                    'entitlements' => [],
                ],
                [
                    'label' => 'Members/Contact',
                    'type' => 'app_page',
                    'reference' => 'members',
                    'entitlements' => [],
                ],
                [
                    'label' => 'Admin',
                    'type' => 'app_page',
                    'reference' => 'admin_dashboard',
                    'entitlements' => ['operator.dashboard.access'],
                ],
                [
                    'label' => 'AI Chat',
                    'type' => 'app_page',
                    'reference' => 'ai_chat',
                    'entitlements' => ['ai_chat.access'],
                ],
                [
                    'label' => 'Logout',
                    'type' => 'action',
                    'reference' => 'logout',
                    'entitlements' => [],
                ],
            ],
            'forum_header' => [
                [
                    'label' => 'Last 24 Hours',
                    'url' => '/forums/today',
                    'target' => '_self',
                    'entitlements' => [],
                ],
                [
                    'label' => 'Subscriptions',
                    'url' => '/profile?tab=subscriptions',
                    'target' => '_self',
                    'entitlements' => ['forums.standard.access', 'operator.dashboard.access'],
                ],
                [
                    'label' => 'Unread Posts',
                    'url' => '/forums/unread',
                    'target' => '_self',
                    'entitlements' => ['forums.standard.access', 'operator.dashboard.access'],
                ],
                [
                    'label' => 'Mark All Read',
                    'url' => '/forums/mark-all-read',
                    'target' => '_self',
                    'entitlements' => ['forums.standard.access', 'operator.dashboard.access'],
                ],
            ],
            'footer_useful' => [
                [
                    'label' => 'About Zen Cart',
                    'url' => '/about',
                    'target' => '_blank',
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Community Forum',
                    'url' => '/forums',
                    'target' => '_self',
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Plugins',
                    'url' => '/plugins',
                    'target' => '_self',
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Storefront Demos',
                    'url' => '{docs}/user/template/demos/',
                    'target' => '_blank',
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Admin Demo',
                    'url' => '{docs}/user/admin/demos/',
                    'target' => '_blank',
                    'type' => 'url',
                    'children' => [],
                    'entitlements' => [],
                ],
            ],
            'footer_legal' => [
                [
                    'label' => 'Privacy Policy',
                    'type' => 'legal_page',
                    'reference' => 'privacy',
                    'target' => '_self',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Cookie Policy',
                    'type' => 'legal_page',
                    'reference' => 'cookie_policy',
                    'target' => '_self',
                    'children' => [],
                    'entitlements' => [],
                ],
                [
                    'label' => 'Donate to the project',
                    'url' => '/donate',
                    'target' => '_self',
                    'type' => 'url',
                    'reference' => '',
                    'children' => [],
                    'entitlements' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{name: string, sort_order: int, html: string}>
     */
    private function footerBlocks(): array
    {
        return [
            'footer_github' => [
                'name' => 'Footer Github',
                'sort_order' => 10,
                'html' => <<<'HTML'
<section class="xl:col-span-4">
    <h3 class="ui-footer-heading">Github</h3>
    <p class="mt-3 max-w-[420px] text-[0.98rem] leading-7 text-[color:var(--color-site-muted)]">
        Zen Cart is in constant development. Please join us on Github and find the latest code there!
    </p>
    <a class="ui-shell-panel mt-6 inline-flex rounded-2xl px-3.5 py-3" href="https://github.com/zencart/zencart" target="_blank" rel="noopener noreferrer">
        <img class="h-auto w-[148px] max-w-full" src="/storage/cms-media/footer-github-logo.png" alt="Zen Cart on Github">
    </a>
</section>
HTML,
            ],
            'footer_newsletter' => [
                'name' => 'Footer Newsletter',
                'sort_order' => 20,
                'html' => <<<'HTML'
<section class="xl:col-span-4">
    <h3 class="ui-footer-heading">Newsletter</h3>
    <p class="mt-3 max-w-[620px] text-[0.98rem] leading-7 text-[color:var(--color-site-muted)]">
        When you sign up, you will receive a newsletter approximately every 2 months with news and updates about Zen Cart. We will not sell your email address or use it for any other purpose.
    </p>
    <a class="ui-btn-secondary mt-5 !px-6 !py-3 !text-sm" href="/newsletter">
        Click here to subscribe
    </a>
</section>
HTML,
            ],
        ];
    }
};
