<?php

declare(strict_types=1);

use ForumKit\Core\Schema\Adopter;
use ForumKit\Core\Schema\AdoptionStatus;
use ForumKit\Core\Schema\SchemaRegistry;
use ForumKit\Core\Schema\TableShape;
use ForumKit\Core\Support\Tables;
use ForumKit\Forums\ForumsServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ZC_FORUM_MIGRATION = '2026_10_11_000000_create_forumkit_forum_tables';
const ZC_MIGRATION = '2026_10_12_000000_add_zencart_columns_to_forum_tables';
const ZC_TABLES = ['forum_groups', 'forums', 'threads', 'posts', 'post_reports', 'post_moderation_logs', 'thread_moderation_logs'];

function forumsMigration(): object
{
    $dir = dirname((new ReflectionClass(ForumsServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.ZC_FORUM_MIGRATION.'.php';
}

function profileMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.ZC_MIGRATION.'.php';
}

/**
 * Tables as zen-cart.com has them: no prefix, migrations not recorded.
 */
function siteTables(): void
{
    config(['forumkit.table_prefix' => '']);

    forumsMigration()->down();
    forumsMigration()->up();
    profileMigration()->up();

    DB::table('migrations')->whereIn('migration', [ZC_FORUM_MIGRATION, ZC_MIGRATION])->delete();
}

afterEach(function () {
    foreach (array_reverse(ZC_TABLES) as $table) {
        Schema::dropIfExists($table);
    }

    config(['forumkit.table_prefix' => 'forumkit_']);
    forumsMigration()->down();
    forumsMigration()->up();
    profileMigration()->up();
});

it('adds the four extras to the forum tables, with their unique keys', function () {
    foreach (['forums' => ['legacy_vbulletin_forum_id', 'is_plugin_support_forum'], 'threads' => ['legacy_vbulletin_thread_id'], 'posts' => ['legacy_post_id']] as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn(Tables::name($table), $column))->toBeTrue("{$table}.{$column}");
        }
    }

    $forums = TableShape::inspect('forums');

    expect($forums->columns['is_plugin_support_forum']['type'])->toBe('tinyint(1)')
        ->and($forums->columns['is_plugin_support_forum']['default'])->toBe('0')
        ->and($forums->columns['legacy_vbulletin_forum_id']['nullable'])->toBeTrue()
        ->and($forums->indexes['forums_legacy_vbulletin_forum_id_unique']['unique'])->toBeTrue()
        ->and(TableShape::inspect('threads')->indexes['threads_legacy_vbulletin_thread_id_unique']['unique'])->toBeTrue()
        ->and(TableShape::inspect('posts')->indexes['posts_legacy_post_id_unique']['unique'])->toBeTrue();
});

it('places the extras where the live tables have them', function () {
    expect(array_slice(array_keys(TableShape::inspect('forums')->columns), 0, 3))->toBe(['id', 'legacy_vbulletin_forum_id', 'forum_group_id'])
        ->and(array_keys(TableShape::inspect('forums')->columns))->toContain('is_plugin_support_forum')
        ->and(array_slice(array_keys(TableShape::inspect('threads')->columns), 0, 2))->toBe(['id', 'legacy_vbulletin_thread_id'])
        ->and(array_slice(array_keys(TableShape::inspect('posts')->columns), 0, 2))->toBe(['id', 'legacy_post_id']);

    $columns = array_keys(TableShape::inspect('forums')->columns);
    expect($columns[array_search('show_when_empty', $columns, true) + 1])->toBe('is_plugin_support_forum');
});

it('equals the forums snapshot plus the declared extension, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $snapshot = $registry->get('forums');
    $extension = $registry->extensionsFor('forums');

    expect($extension)->toHaveCount(1)
        ->and($extension[0]->name)->toBe('zencart')
        ->and($extension[0]->migrationNames())->toBe([ZC_MIGRATION]);

    $extended = $snapshot->extendedBy($extension);

    foreach (['forums', 'threads', 'posts'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('adopts the live site\'s tables with zero differences once the profile is installed', function () {
    siteTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([ZC_FORUM_MIGRATION, ZC_MIGRATION]);
});

it('records the profile\'s migration as run when it adopts', function () {
    siteTables();

    $this->artisan('forumkit:adopt', ['--package' => 'forums'])->assertExitCode(0);

    expect(DB::table('migrations')->whereIn('migration', [ZC_FORUM_MIGRATION, ZC_MIGRATION])->count())->toBe(2);
});

it('refuses a site whose extra column differs from the declared one', function () {
    siteTables();
    Schema::table('posts', fn ($table) => $table->string('legacy_post_id', 20)->nullable()->change());

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('posts: column legacy_post_id type is varchar(20), expected bigint unsigned');
});

it('refuses the generic package tables when the profile\'s extras are missing', function () {
    config(['forumkit.table_prefix' => '']);
    forumsMigration()->down();
    forumsMigration()->up();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('posts: column legacy_post_id is missing');
});

it('rolls its migration back cleanly', function () {
    profileMigration()->down();

    expect(Schema::hasColumn(Tables::name('posts'), 'legacy_post_id'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('forums'), 'is_plugin_support_forum'))->toBeFalse()
        ->and(TableShape::inspect('forums')->differencesFrom(app(SchemaRegistry::class)->get('forums')->tables['forums']))->toBe([]);

    profileMigration()->up();
});
