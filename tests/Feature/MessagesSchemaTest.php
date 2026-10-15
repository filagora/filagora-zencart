<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Filagora\Messages\MessagesServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const ZC_MESSAGES_MIGRATION = '2026_10_15_000000_create_filagora_message_tables';
const ZC_MESSAGES_PROFILE_MIGRATION = '2026_10_15_400000_add_zencart_columns_to_message_tables';

function messagesMigration(): object
{
    $dir = dirname((new ReflectionClass(MessagesServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.ZC_MESSAGES_MIGRATION.'.php';
}

function messagesProfileMigration(): object
{
    return require __DIR__.'/../../database/migrations/messages/'.ZC_MESSAGES_PROFILE_MIGRATION.'.php';
}

/**
 * The message tables as zen-cart.com has them: no prefix, migrations not recorded. The prefixed
 * set goes first because two foreign key names are database-wide and carry no prefix.
 */
function siteMessageTables(): void
{
    messagesProfileMigration()->down();
    messagesMigration()->down();
    config(['filagora.table_prefix' => '']);

    messagesMigration()->down();
    messagesMigration()->up();
    messagesProfileMigration()->up();
    DB::table('migrations')->whereIn('migration', [ZC_MESSAGES_MIGRATION, ZC_MESSAGES_PROFILE_MIGRATION])->delete();
}

afterEach(function () {
    if (config('filagora.table_prefix') === '') {
        messagesProfileMigration()->down();
        messagesMigration()->down();
        config(['filagora.table_prefix' => 'filagora_']);
        messagesMigration()->up();
        messagesProfileMigration()->up();
    }
});

it('adds the legacy ids to the conversation and message tables, unique and where the live tables have them', function () {
    $conversations = TableShape::inspect('private_conversations');
    $messages = TableShape::inspect('private_messages');

    expect(array_slice(array_keys($conversations->columns), 0, 2))->toBe(['id', 'legacy_root_pmid'])
        ->and($conversations->indexes['private_conversations_legacy_root_pmid_unique']['unique'])->toBeTrue()
        ->and(array_slice(array_keys($messages->columns), 0, 2))->toBe(['id', 'legacy_pmtext_id'])
        ->and($messages->columns['legacy_pmtext_id']['nullable'])->toBeTrue()
        ->and($messages->indexes['private_messages_legacy_pmtext_id_unique']['unique'])->toBeTrue();
});

it('equals the messages snapshot plus the declared extension, exactly', function () {
    $registry = app(SchemaRegistry::class);
    $extension = $registry->extensionsFor('messages');

    expect($extension)->toHaveCount(1)
        ->and($extension[0]->name)->toBe('zencart')
        ->and($extension[0]->migrationNames())->toBe([ZC_MESSAGES_PROFILE_MIGRATION]);

    $extended = $registry->get('messages')->extendedBy($extension);

    foreach (array_keys($extended->tables) as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('adopts the live site\'s message tables with zero differences once the profile is installed', function () {
    siteMessageTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([ZC_MESSAGES_MIGRATION, ZC_MESSAGES_PROFILE_MIGRATION]);
});

it('refuses the generic message tables when the profile\'s columns are missing', function () {
    messagesProfileMigration()->down();
    messagesMigration()->down();
    config(['filagora.table_prefix' => '']);
    messagesMigration()->down();
    messagesMigration()->up();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('private_conversations: column legacy_root_pmid is missing', 'private_messages: column legacy_pmtext_id is missing');

    messagesProfileMigration()->up();
});

it('rolls its migration back cleanly', function () {
    messagesProfileMigration()->down();

    expect(Schema::hasColumn(Tables::name('private_conversations'), 'legacy_root_pmid'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('private_messages'), 'legacy_pmtext_id'))->toBeFalse();

    foreach (['private_conversations', 'private_messages'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom(app(SchemaRegistry::class)->get('messages')->tables[$base]))->toBe([]);
    }

    messagesProfileMigration()->up();
});
