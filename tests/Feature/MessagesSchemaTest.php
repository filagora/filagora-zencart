<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => restoreStack());

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
        ->and($extension[0]->migrationNames())->toBe([STACK_MESSAGES_PROFILE]);

    $extended = $registry->get('messages')->extendedBy($extension);

    foreach (array_keys($extended->tables) as $base) {
        expect(TableShape::inspect($base)->differencesFrom($extended->tables[$base]))->toBe([], $base);
    }
});

it('adopts the live site\'s message tables with zero differences once the profile is installed', function () {
    liveSiteStack();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toEqualCanonicalizing([STACK_MESSAGES_MIGRATION, STACK_MESSAGES_PROFILE]);
});

it('refuses the generic message tables when the profile\'s columns are missing', function () {
    liveSiteStack(withProfile: false);

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('private_conversations: column legacy_root_pmid is missing', 'private_messages: column legacy_pmtext_id is missing');
});

it('rolls its migration back cleanly', function () {
    stackProfileMigration(STACK_MESSAGES_PROFILE)->down();

    expect(Schema::hasColumn(Tables::name('private_conversations'), 'legacy_root_pmid'))->toBeFalse()
        ->and(Schema::hasColumn(Tables::name('private_messages'), 'legacy_pmtext_id'))->toBeFalse();

    foreach (['private_conversations', 'private_messages'] as $base) {
        expect(TableShape::inspect($base)->differencesFrom(app(SchemaRegistry::class)->get('messages')->tables[$base]))->toBe([]);
    }

    stackProfileMigration(STACK_MESSAGES_PROFILE)->up();
});
