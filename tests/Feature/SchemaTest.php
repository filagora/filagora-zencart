<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => restoreStack());

it('adds the extras to the forum tables, with their unique keys', function () {
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

it('puts the attachment extras where the live table has them, with its own lookups instead of the plain ones', function () {
    $attachments = TableShape::inspect('post_attachments');
    $columns = array_keys($attachments->columns);

    expect(array_slice($columns, 0, 10))->toBe(['id', 'legacy_attachment_id', 'legacy_filedata_id', 'legacy_post_id', 'legacy_pmtext_id', 'post_id', 'thread_id', 'private_message_id', 'private_conversation_id', 'uploader_id'])
        ->and($columns[array_search('thumbnail_storage_path', $columns, true) + 1])->toBe('legacy_state')
        ->and($columns[array_search('legacy_state', $columns, true) + 1])->toBe('legacy_download_count')
        ->and($columns[array_search('download_count', $columns, true) + 1])->toBe('legacy_created_at')
        ->and($attachments->columns['legacy_download_count'])->toMatchArray(['type' => 'int unsigned', 'nullable' => false, 'default' => '0'])
        ->and($attachments->columns['legacy_created_at'])->toMatchArray(['type' => 'datetime', 'nullable' => true])
        ->and($attachments->indexes['post_attachments_legacy_attachment_id_unique']['unique'])->toBeTrue()
        ->and($attachments->indexes['post_attachments_post_id_legacy_attachment_id_index']['columns'])->toBe(['post_id', 'legacy_attachment_id'])
        ->and($attachments->indexes['post_attachments_thread_id_legacy_attachment_id_index']['columns'])->toBe(['thread_id', 'legacy_attachment_id'])
        ->and($attachments->indexes)->not->toHaveKeys(['post_attachments_post_id_foreign', 'post_attachments_thread_id_foreign'])
        ->and($attachments->foreignKeys)->toHaveKeys(['post_attachments_post_id_foreign', 'post_attachments_thread_id_foreign']);
});

it('equals the forums snapshot plus the declared extensions, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $snapshot = $registry->get('forums');
    $extensions = $registry->extensionsFor('forums');
    $zencart = collect($extensions)->firstWhere('name', 'zencart');

    expect($extensions)->toHaveCount(2)
        ->and($zencart->migrationNames())->toBe([STACK_FORUMS_PROFILE[0], STACK_FORUMS_PROFILE[1], STACK_FORUMS_PROFILE[2]]);

    $extended = $snapshot->extendedBy($extensions);

    foreach (['forums', 'threads', 'posts', 'reactions', 'post_attachments'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('adopts the live site\'s tables with zero differences once the profile is installed', function () {
    liveSiteStack();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([...STACK_FORUMS_MIGRATIONS, ...STACK_FORUMS_PROFILE, STACK_MESSAGES_LINK_MIGRATION]);
});

it('records the profile\'s migrations as run when it adopts', function () {
    liveSiteStack();

    $this->artisan('filagora:adopt', ['--package' => 'forums'])->assertExitCode(0);

    expect(DB::table('migrations')->whereIn('migration', [...STACK_FORUMS_MIGRATIONS, ...STACK_FORUMS_PROFILE, STACK_MESSAGES_LINK_MIGRATION])->count())->toBe(12);
});

it('refuses a site whose extra column differs from the declared one', function () {
    liveSiteStack();
    Schema::table('posts', fn ($table) => $table->string('legacy_post_id', 20)->nullable()->change());

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('posts: column legacy_post_id type is varchar(20), expected bigint unsigned');
});

it('refuses a site whose attachment lookups differ from the declared ones', function () {
    liveSiteStack();
    Schema::table('post_attachments', fn ($table) => $table->index('post_id', 'post_attachments_post_id_foreign'));
    Schema::table('post_attachments', fn ($table) => $table->dropIndex('post_attachments_post_id_legacy_attachment_id_index'));

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('post_attachments: index post_attachments_post_id_legacy_attachment_id_index is missing')
        ->and($plan->differences)->toContain('post_attachments: index post_attachments_post_id_foreign is not expected');
});

it('refuses the generic package tables when the profile\'s extras are missing', function () {
    liveSiteStack(withProfile: false);

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('forums'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('posts: column legacy_post_id is missing')
        ->and($plan->differences)->toContain('post_attachments: column legacy_attachment_id is missing')
        ->and($plan->differences)->toContain('post_attachments: index post_attachments_post_id_foreign is not expected');
});

it('rolls its migrations back cleanly', function () {
    foreach (array_reverse(STACK_FORUMS_PROFILE) as $name) {
        stackProfileMigration($name)->down();
    }

    $registry = app(SchemaRegistry::class);
    $base = $registry->get('forums')->extendedBy(array_values(array_filter($registry->extensionsFor('forums'), fn ($extension) => $extension->name === 'messages')));

    expect(Schema::hasColumn(Tables::name('posts'), 'legacy_post_id'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('forums'), 'is_plugin_support_forum'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('post_attachments'), 'legacy_attachment_id'))->toBeFalse()
        ->and(TableShape::inspect('forums')->differencesFrom($base->tables['forums']))->toBe([])
        ->and(TableShape::inspect('post_attachments')->differencesFrom($base->tables['post_attachments']))->toBe([]);

    foreach (STACK_FORUMS_PROFILE as $name) {
        stackProfileMigration($name)->up();
    }
});
