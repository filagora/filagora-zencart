<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => restoreStack());

it('adds the legacy library id and the install folder to plugins, unique and where the live table has them', function () {
    $plugins = TableShape::inspect('plugins');

    expect(array_slice(array_keys($plugins->columns), 0, 4))->toBe(['id', 'legacy_vbulletin_file_id', 'zip_root', 'name'])
        ->and($plugins->columns['legacy_vbulletin_file_id'])->toMatchArray(['type' => 'bigint unsigned', 'nullable' => true])
        ->and($plugins->columns['zip_root'])->toMatchArray(['type' => 'varchar(180)', 'nullable' => true])
        ->and($plugins->indexes['plugins_legacy_vbulletin_file_id_unique']['unique'])->toBeTrue()
        ->and($plugins->indexes['plugins_zip_root_unique']['unique'])->toBeTrue();
});

it('adds the version folder and the encapsulated flag to releases, where the live table has them', function () {
    $releases = TableShape::inspect('plugin_releases');
    $columns = array_keys($releases->columns);

    expect($columns[array_search('version', $columns, true) + 1])->toBe('version_folder')
        ->and($columns[array_search('changelog', $columns, true) + 1])->toBe('encapsulated')
        ->and($releases->columns['version_folder'])->toMatchArray(['type' => 'varchar(40)', 'nullable' => true])
        ->and($releases->columns['encapsulated'])->toMatchArray(['type' => 'varchar(16)', 'nullable' => false, 'default' => 'unknown']);
});

it('equals the plugins snapshot plus the declared extension, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $extension = collect($registry->extensionsFor('plugins'))->firstWhere('name', 'zencart');

    expect($extension)->not->toBeNull()
        ->and($extension->migrationNames())->toBe([STACK_PLUGINS_PROFILE]);

    // With forums installed the plugins package declares its own thread link as well.
    $extended = $registry->get('plugins')->extendedBy($registry->extensionsFor('plugins'));

    foreach (['plugins', 'plugin_releases'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('ships the two Zen Cart version tables as a snapshot of their own that matches the migration', function () {
    $snapshot = app(SchemaRegistry::class)->get('zencart');

    expect(array_keys($snapshot->tables))->toBe(['plugin_zc_versions', 'plugin_release_zc_versions'])
        ->and($snapshot->migrationNames())->toBe([STACK_ZC_VERSIONS_MIGRATION]);

    foreach ($snapshot->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('keeps the names, uniqueness and cascades the live version tables have', function () {
    $versions = TableShape::inspect('plugin_zc_versions');
    $links = TableShape::inspect('plugin_release_zc_versions');

    expect($versions->columns['version']['type'])->toBe('varchar(40)')
        ->and($versions->columns['is_active']['default'])->toBe('1')
        ->and($versions->columns['position']['default'])->toBe('0')
        ->and($versions->indexes['plugin_zc_versions_version_unique']['unique'])->toBeTrue()
        ->and($versions->indexes['plugin_zc_versions_active_position_version_idx']['columns'])->toBe(['is_active', 'position', 'version'])
        ->and($links->indexes['plugin_release_zc_unique'])->toMatchArray(['columns' => ['plugin_release_id', 'plugin_zc_version_id'], 'unique' => true])
        ->and($links->foreignKeys['plugin_release_zc_versions_plugin_release_id_foreign']['on_delete'])->toBe('cascade')
        ->and($links->foreignKeys['plugin_release_zc_versions_plugin_zc_version_id_foreign']['on_delete'])->toBe('cascade');
});

it('adopts the live site\'s plugin tables with zero differences once the profile is installed', function () {
    liveSiteStack();

    $plugins = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('plugins'));
    $profile = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('zencart'));

    expect($plugins->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plugins->differences))
        ->and($plugins->pendingMigrations)->toEqualCanonicalizing([STACK_PLUGINS_MIGRATION, STACK_PLUGINS_THREAD_MIGRATION, STACK_PLUGINS_PROFILE])
        ->and($profile->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $profile->differences))
        ->and($profile->pendingMigrations)->toBe([STACK_ZC_VERSIONS_MIGRATION]);
});

it('refuses the generic plugin tables when the profile\'s columns are missing', function () {
    liveSiteStack(withProfile: false);

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('plugins'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('plugins: column legacy_vbulletin_file_id is missing', 'plugin_releases: column encapsulated is missing');
});

it('is already adopted on a site that ran the migrations', function () {
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('zencart'))->status)->toBe(AdoptionStatus::AlreadyAdopted)
        ->and(Tables::name('plugin_zc_versions'))->toBe('filagora_plugin_zc_versions');
});

it('rolls its migrations back cleanly', function () {
    stackProfileMigration(STACK_ZC_VERSIONS_MIGRATION)->down();
    stackProfileMigration(STACK_PLUGINS_PROFILE)->down();

    expect(Schema::hasTable(Tables::name('plugin_zc_versions')))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('plugins'), 'zip_root'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('plugin_releases'), 'encapsulated'))->toBeFalse();

    $registry = app(SchemaRegistry::class);
    $withoutProfile = $registry->get('plugins')->extendedBy(array_values(array_filter($registry->extensionsFor('plugins'), fn ($extension) => $extension->name !== 'zencart')));

    foreach (['plugins', 'plugin_releases'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom($withoutProfile->tables[$base]))->toBe([], $base);
    }

    stackProfileMigration(STACK_PLUGINS_PROFILE)->up();
    stackProfileMigration(STACK_ZC_VERSIONS_MIGRATION)->up();
});
