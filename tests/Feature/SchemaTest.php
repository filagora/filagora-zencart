<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Filagora\Forums\ForumsServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ZC_FORUM_MIGRATION = '2026_10_11_000000_create_filagora_forum_tables';
const ZC_ANNOUNCEMENT_MIGRATION = '2026_10_14_000000_create_filagora_announcement_tables';
const ZC_POLL_MIGRATION = '2026_10_15_000000_create_filagora_poll_tables';
const ZC_REACTION_MIGRATION = '2026_10_15_100000_create_filagora_reaction_tables';
const ZC_SUBSCRIPTION_MIGRATION = '2026_10_15_200000_create_filagora_subscription_tables';
const ZC_PACKAGE_MIGRATIONS = [ZC_FORUM_MIGRATION, ZC_ANNOUNCEMENT_MIGRATION, ZC_POLL_MIGRATION, ZC_REACTION_MIGRATION, ZC_SUBSCRIPTION_MIGRATION];
const ZC_REACTION_PROFILE_MIGRATION = '2026_10_15_300000_add_zencart_columns_to_reaction_table';
const ZC_MIGRATION = '2026_10_12_000000_add_zencart_columns_to_forum_tables';
const ZC_TABLES = ['forum_groups', 'forums', 'threads', 'posts', 'post_reports', 'post_moderation_logs', 'thread_moderation_logs', 'site_notifications', 'user_site_notification_dismissals', 'polls', 'poll_questions', 'poll_options', 'poll_votes', 'poll_vote_answers', 'reactions', 'thread_subscriptions', 'thread_subscription_digest_items'];

function packageMigration(string $name): object
{
    $dir = dirname((new ReflectionClass(ForumsServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.$name.'.php';
}

/**
 * Take the forums package's tables down (newest migration first, because later ones point at the
 * forum tables) and bring them up again, with the prefix as it is now.
 */
function rebuildPackageTables(): void
{
    foreach (array_reverse(ZC_PACKAGE_MIGRATIONS) as $name) {
        packageMigration($name)->down();
    }

    foreach (ZC_PACKAGE_MIGRATIONS as $name) {
        packageMigration($name)->up();
    }
}

/**
 * The forums package's own tables with no prefix. Some constraint names do not carry the prefix,
 * so the prefixed set has to go first; afterEach() puts it back.
 */
function genericSiteTables(): void
{
    foreach (array_reverse(ZC_PACKAGE_MIGRATIONS) as $name) {
        packageMigration($name)->down();
    }

    config(['filagora.table_prefix' => '']);

    rebuildPackageTables();
}

function profileMigration(): object
{
    return new class
    {
        public function up(): void
        {
            foreach ([ZC_MIGRATION, ZC_REACTION_PROFILE_MIGRATION] as $name) {
                (require __DIR__.'/../../database/migrations/'.$name.'.php')->up();
            }
        }

        public function down(): void
        {
            foreach ([ZC_REACTION_PROFILE_MIGRATION, ZC_MIGRATION] as $name) {
                (require __DIR__.'/../../database/migrations/'.$name.'.php')->down();
            }
        }
    };
}

/**
 * Tables as zen-cart.com has them: no prefix, migrations not recorded.
 */
function siteTables(): void
{
    genericSiteTables();
    profileMigration()->up();

    DB::table('migrations')->whereIn('migration', [...ZC_PACKAGE_MIGRATIONS, ZC_MIGRATION, ZC_REACTION_PROFILE_MIGRATION])->delete();
}

afterEach(function () {
    foreach (array_reverse(ZC_TABLES) as $table) {
        Schema::dropIfExists($table);
    }

    config(['filagora.table_prefix' => 'filagora_']);
    rebuildPackageTables();
    profileMigration()->up();
});

it('adds the five extras to the forum tables, with their unique keys', function () {
    foreach (['forums' => ['legacy_vbulletin_forum_id', 'is_plugin_support_forum'], 'threads' => ['legacy_vbulletin_thread_id'], 'posts' => ['legacy_post_id'], 'reactions' => ['legacy_reaction_id']] as $table => $columns) {
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
        ->and(TableShape::inspect('posts')->indexes['posts_legacy_post_id_unique']['unique'])->toBeTrue()
        ->and(TableShape::inspect('reactions')->indexes['reactions_legacy_reaction_id_unique']['unique'])->toBeTrue();
});

it('places the extras where the live tables have them', function () {
    expect(array_slice(array_keys(TableShape::inspect('forums')->columns), 0, 3))->toBe(['id', 'legacy_vbulletin_forum_id', 'forum_group_id'])
        ->and(array_keys(TableShape::inspect('forums')->columns))->toContain('is_plugin_support_forum')
        ->and(array_slice(array_keys(TableShape::inspect('threads')->columns), 0, 2))->toBe(['id', 'legacy_vbulletin_thread_id'])
        ->and(array_slice(array_keys(TableShape::inspect('posts')->columns), 0, 2))->toBe(['id', 'legacy_post_id'])
        ->and(array_slice(array_keys(TableShape::inspect('reactions')->columns), 0, 3))->toBe(['id', 'legacy_reaction_id', 'post_id']);

    $columns = array_keys(TableShape::inspect('forums')->columns);
    expect($columns[array_search('show_when_empty', $columns, true) + 1])->toBe('is_plugin_support_forum');
});

it('equals the forums snapshot plus the declared extension, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $snapshot = $registry->get('forums');
    $extension = $registry->extensionsFor('forums');

    expect($extension)->toHaveCount(1)
        ->and($extension[0]->name)->toBe('zencart')
        ->and($extension[0]->migrationNames())->toBe([ZC_MIGRATION, ZC_REACTION_PROFILE_MIGRATION]);

    $extended = $snapshot->extendedBy($extension);

    foreach (['forums', 'threads', 'posts', 'reactions'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('adopts the live site\'s tables with zero differences once the profile is installed', function () {
    siteTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([...ZC_PACKAGE_MIGRATIONS, ZC_MIGRATION, ZC_REACTION_PROFILE_MIGRATION]);
});

it('records the profile\'s migration as run when it adopts', function () {
    siteTables();

    $this->artisan('filagora:adopt', ['--package' => 'forums'])->assertExitCode(0);

    expect(DB::table('migrations')->whereIn('migration', [...ZC_PACKAGE_MIGRATIONS, ZC_MIGRATION, ZC_REACTION_PROFILE_MIGRATION])->count())->toBe(7);
});

it('refuses a site whose extra column differs from the declared one', function () {
    siteTables();
    Schema::table('posts', fn ($table) => $table->string('legacy_post_id', 20)->nullable()->change());

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('posts: column legacy_post_id type is varchar(20), expected bigint unsigned');
});

it('refuses the generic package tables when the profile\'s extras are missing', function () {
    genericSiteTables();

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
