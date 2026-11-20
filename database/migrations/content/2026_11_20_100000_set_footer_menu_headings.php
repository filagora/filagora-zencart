<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The footer's "Useful Links" and "Legal" headings, now a menu's own heading (set on the dashboard)
 * instead of text in the template. Gives each the heading the live site shows, so the menu form is
 * not empty and nothing visible changes.
 *
 * It only fills a heading that is empty: one staff have already set is left alone. It does nothing
 * when the menus table has no `title` column (a filagora-cms that predates menu headings), and
 * rolling it back removes nothing, for the same reason as the migration that creates the menus.
 */
return new class extends Migration
{
    private const HEADINGS = [
        'footer_useful' => 'Useful Links',
        'footer_legal' => 'Legal',
    ];

    public function up(): void
    {
        $navigations = Tables::name('cms_navigations');

        if (! Schema::hasTable($navigations) || ! Schema::hasColumn($navigations, 'title')) {
            return;
        }

        foreach (self::HEADINGS as $name => $heading) {
            DB::table($navigations)
                ->where('name', $name)
                ->where(fn ($query) => $query->whereNull('title')->orWhere('title', ''))
                ->update(['title' => $heading, 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
