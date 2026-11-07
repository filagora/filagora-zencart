<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * A site's pages, with their blocks, and the pictures those blocks show, created from a directory the
 * site keeps (`filagora-zencart.content.path`), so a new site starts with its real home, about,
 * download and other pages instead of bare shells. With no directory configured it does nothing.
 *
 * The directory holds:
 *   pages.php  returns a list of pages: slug, custom_route, title, excerpt, seo_title, seo_description,
 *              published_at and blocks (each: type, name, sort_order, data)
 *   media.php  returns a list of pictures: [path, filename, mime type, alt text, meta]
 *   media/     the picture files, at the same paths (`cms-media/...`) they have on the `public` disk
 *
 * It only ever creates. A page that exists (by slug, or by the route it answers) is left exactly as it
 * is, blocks and all, so a live database and any site whose staff have edited a page are untouched; a
 * picture that is already on the disk is not overwritten. Rolling it back removes nothing.
 *
 * Addresses that belong to the environment are written as `{name}` placeholders and filled in from
 * `filagora-zencart.content.placeholders`. A placeholder with no value makes a link that starts with it
 * `#`, and a `mailto:` link to it plain text, rather than a dead link.
 */
return new class extends Migration
{
    public function up(): void
    {
        $directory = rtrim((string) config('filagora-zencart.content.path'), '/');

        if ($directory === '' || ! is_file($directory.'/pages.php')) {
            return;
        }

        $pages = Tables::name('cms_pages');
        $blocks = Tables::name('cms_blocks');
        $assets = Tables::name('cms_media_assets');

        if (! Schema::hasTable($pages) || ! Schema::hasTable($blocks) || ! Schema::hasTable($assets)) {
            return;
        }

        $now = now();

        foreach ($this->load($directory.'/media.php') as [$path, $filename, $mime, $alt, $meta]) {
            $this->copyPicture($directory, $path);

            if (! DB::table($assets)->where('path', $path)->exists()) {
                DB::table($assets)->insert([
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mime_type' => $mime,
                    'size' => Storage::disk('public')->exists($path) ? Storage::disk('public')->size($path) : null,
                    'alt_text' => $alt,
                    'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($this->load($directory.'/pages.php') as $page) {
            if (DB::table($pages)->where('slug', $page['slug'])->orWhere('custom_route', $page['custom_route'])->exists()) {
                continue;
            }

            $pageId = DB::table($pages)->insertGetId([
                'slug' => $page['slug'],
                'custom_route' => $page['custom_route'],
                'title' => $this->filled($page['title']),
                'excerpt' => $this->filled($page['excerpt']),
                'status' => 'published',
                'published_at' => $page['published_at'],
                'seo_title' => $this->filled($page['seo_title']),
                'seo_description' => $this->filled($page['seo_description']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($page['blocks'] as $block) {
                DB::table($blocks)->insert([
                    'cms_page_id' => $pageId,
                    'type' => $block['type'],
                    'name' => $block['name'],
                    'sort_order' => $block['sort_order'],
                    'is_reusable' => false,
                    'is_published' => true,
                    'is_draft' => false,
                    'data' => json_encode($this->filled($block['data']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Not reversible: removing the pages would also remove whatever staff have made of them.
     */
    public function down(): void {}

    /**
     * @return list<array<array-key, mixed>>
     */
    private function load(string $file): array
    {
        $rows = is_file($file) ? require $file : [];

        return is_array($rows) ? array_values($rows) : [];
    }

    private function copyPicture(string $directory, string $path): void
    {
        $disk = Storage::disk('public');
        $source = $directory.'/media/'.$path;

        if (! $disk->exists($path) && is_file($source)) {
            $disk->put($path, (string) file_get_contents($source));
        }
    }

    /**
     * Put the environment's addresses where the placeholders are, in a string or all through a block's data.
     */
    private function filled(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $inner): mixed => $this->filled($inner), $value);
        }

        if (! is_string($value) || ! str_contains($value, '{')) {
            return $value;
        }

        foreach ((array) config('filagora-zencart.content.placeholders') as $name => $replacement) {
            $token = '{'.$name.'}';
            $replacement = trim((string) $replacement);

            if (! str_contains($value, $token)) {
                continue;
            }

            if ($replacement === '') {
                if (str_starts_with($value, $token)) {
                    return '#';
                }

                $value = (string) preg_replace('~(?:<!--email_off-->)?<a href="mailto:'.preg_quote($token, '~').'">(.*?)</a>(?:<!--/email_off-->)?~s', '$1', $value);

                continue;
            }

            $value = str_replace($token, rtrim($replacement, '/'), $value);
        }

        return $value;
    }
};
