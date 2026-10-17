<?php

declare(strict_types=1);

use Filagora\Cms\CmsServiceProvider;
use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ZC_CMS_MIGRATION = '2026_10_12_100000_create_filagora_cms_tables';
const ZC_CMS_PROFILE_MIGRATION = '2026_10_13_000000_add_zencart_columns_to_cms_tables';

function cmsMigration(): object
{
    $dir = dirname((new ReflectionClass(CmsServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.ZC_CMS_MIGRATION.'.php';
}

function cmsProfileMigration(): object
{
    return require __DIR__.'/../../database/migrations/cms/'.ZC_CMS_PROFILE_MIGRATION.'.php';
}

/**
 * The cms tables as zen-cart.com has them: no prefix, migrations not recorded.
 */
function siteCmsTables(): void
{
    config(['filagora.table_prefix' => '']);

    cmsMigration()->down();
    cmsMigration()->up();
    cmsProfileMigration()->up();
    DB::table('migrations')->whereIn('migration', [ZC_CMS_MIGRATION, ZC_CMS_PROFILE_MIGRATION])->delete();
}

afterEach(function () {
    if (config('filagora.table_prefix') === '') {
        cmsMigration()->down();
    }

    config(['filagora.table_prefix' => 'filagora_']);
    cmsMigration()->down();
    cmsMigration()->up();
    cmsProfileMigration()->up();
});

it('adds the legacy content id to the pages table, unique and where the live table has it', function () {
    $pages = TableShape::inspect('cms_pages');

    expect(array_slice(array_keys($pages->columns), 0, 2))->toBe(['id', 'legacy_vbulletin_content_id'])
        ->and($pages->columns['legacy_vbulletin_content_id']['type'])->toBe('bigint unsigned')
        ->and($pages->columns['legacy_vbulletin_content_id']['nullable'])->toBeTrue()
        ->and($pages->indexes['cms_pages_legacy_vbulletin_content_id_unique']['unique'])->toBeTrue();
});

it('equals the cms snapshot plus the declared extension, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $extension = $registry->extensionsFor('cms');

    expect($extension)->toHaveCount(1)
        ->and($extension[0]->name)->toBe('zencart')
        ->and($extension[0]->migrationNames())->toBe([ZC_CMS_PROFILE_MIGRATION]);

    $extended = $registry->get('cms')->extendedBy($extension);

    foreach (array_keys($extended->tables) as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('keeps the forums extension to its own migration', function () {
    $extension = collect(app(SchemaRegistry::class)->extensionsFor('forums'))->firstWhere('name', 'zencart');

    expect($extension->migrationNames())->toBe(['2026_10_12_000000_add_zencart_columns_to_forum_tables', '2026_10_15_300000_add_zencart_columns_to_reaction_table', '2026_10_16_400000_add_zencart_columns_to_attachment_table']);
});

it('adopts the live site\'s cms tables with zero differences once the profile is installed', function () {
    siteCmsTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('cms'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([ZC_CMS_MIGRATION, ZC_CMS_PROFILE_MIGRATION]);
});

it('refuses the generic cms tables when the profile\'s column is missing', function () {
    config(['filagora.table_prefix' => '']);
    cmsMigration()->down();
    cmsMigration()->up();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('cms'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('cms_pages: column legacy_vbulletin_content_id is missing');
});

it('rolls its migration back cleanly', function () {
    cmsProfileMigration()->down();

    expect(Schema::hasColumn(Tables::name('cms_pages'), 'legacy_vbulletin_content_id'))->toBeFalse()
        ->and(TableShape::inspect('cms_pages')->differencesFrom(app(SchemaRegistry::class)->get('cms')->tables['cms_pages']))->toBe([]);

    cmsProfileMigration()->up();
});
