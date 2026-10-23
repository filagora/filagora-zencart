<?php

declare(strict_types=1);

use Filagora\Forums\ForumsServiceProvider;
use Filagora\Messages\Attachments\AttachmentLinks;
use Filagora\Messages\MessagesServiceProvider;
use Filagora\Plugins\PluginsServiceProvider;
use Illuminate\Support\Facades\DB;

/*
 * The forum, message and profile tables depend on each other (the attachments table points at
 * posts, threads and private messages), so the schema tests build and remove them together, in
 * dependency order, rather than package by package.
 */

const STACK_FORUMS_MIGRATIONS = [
    '2026_10_11_000000_create_filagora_forum_tables',
    '2026_10_14_000000_create_filagora_announcement_tables',
    '2026_10_15_000000_create_filagora_poll_tables',
    '2026_10_15_100000_create_filagora_reaction_tables',
    '2026_10_15_200000_create_filagora_subscription_tables',
    '2026_10_16_000000_create_filagora_read_tracking_tables',
    '2026_10_16_100000_create_filagora_mention_table',
    '2026_10_16_200000_create_filagora_attachment_table',
];
const STACK_ATTACHMENT_MIGRATION = '2026_10_16_200000_create_filagora_attachment_table';
const STACK_MESSAGES_MIGRATION = '2026_10_15_000000_create_filagora_message_tables';
const STACK_IGNORED_MIGRATION = '2026_10_22_000000_create_filagora_ignored_users_table';
const STACK_PLUGINS_MIGRATION = '2026_10_19_000000_create_filagora_plugin_tables';
const STACK_PLUGINS_THREAD_MIGRATION = '2026_10_19_100000_add_thread_foreign_key_to_plugins_table';
const STACK_PLUGINS_PROFILE = '2026_10_22_000000_add_zencart_columns_to_plugin_tables';
const STACK_ZC_VERSIONS_MIGRATION = '2026_10_22_100000_create_zencart_plugin_version_tables';
const STACK_MESSAGES_LINK_MIGRATION = '2026_10_16_300000_add_message_links_to_attachments_table';
const STACK_FORUMS_PROFILE = [
    '2026_10_12_000000_add_zencart_columns_to_forum_tables',
    '2026_10_15_300000_add_zencart_columns_to_reaction_table',
    '2026_10_16_400000_add_zencart_columns_to_attachment_table',
];
const STACK_MESSAGES_PROFILE = '2026_10_15_400000_add_zencart_columns_to_message_tables';

function stackForumsMigration(string $name): object
{
    $dir = dirname((new ReflectionClass(ForumsServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.$name.'.php';
}

function stackMessagesMigration(): object
{
    $dir = dirname((new ReflectionClass(MessagesServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.STACK_MESSAGES_MIGRATION.'.php';
}

function stackIgnoredMigration(): object
{
    $dir = dirname((new ReflectionClass(MessagesServiceProvider::class))->getFileName(), 2);

    return require $dir.'/database/migrations/'.STACK_IGNORED_MIGRATION.'.php';
}

function stackPluginsMigration(string $name): object
{
    $dir = dirname((new ReflectionClass(PluginsServiceProvider::class))->getFileName(), 2);
    $sub = $name === STACK_PLUGINS_THREAD_MIGRATION ? 'forums/' : '';

    return require $dir.'/database/migrations/'.$sub.$name.'.php';
}

function stackProfileMigration(string $name): object
{
    $dir = match ($name) {
        STACK_MESSAGES_PROFILE => 'messages/',
        STACK_PLUGINS_PROFILE => 'plugins/',
        STACK_ZC_VERSIONS_MIGRATION => 'zc-versions/',
        default => '',
    };

    return require __DIR__.'/../../database/migrations/'.$dir.$name.'.php';
}

/**
 * Every migration the stack runs, for recording or forgetting them as run.
 *
 * @return list<string>
 */
function stackMigrationNames(bool $withProfile = true): array
{
    return [...STACK_FORUMS_MIGRATIONS, STACK_MESSAGES_MIGRATION, STACK_IGNORED_MIGRATION, STACK_PLUGINS_MIGRATION, STACK_PLUGINS_THREAD_MIGRATION, STACK_MESSAGES_LINK_MIGRATION, ...($withProfile ? [...STACK_FORUMS_PROFILE, STACK_MESSAGES_PROFILE, STACK_PLUGINS_PROFILE, STACK_ZC_VERSIONS_MIGRATION] : [])];
}

/**
 * Remove every table of the stack, with the prefix as it is now: the attachments first (they point
 * at the others), then the message tables, then the rest of the forum tables, newest first.
 */
function stackDown(): void
{
    stackProfileMigration(STACK_ZC_VERSIONS_MIGRATION)->down();
    stackPluginsMigration(STACK_PLUGINS_MIGRATION)->down();
    stackForumsMigration(STACK_ATTACHMENT_MIGRATION)->down();
    stackIgnoredMigration()->down();
    stackMessagesMigration()->down();

    foreach (array_reverse(STACK_FORUMS_MIGRATIONS) as $name) {
        stackForumsMigration($name)->down();
    }
}

/**
 * Build the stack with the prefix as it is now.
 */
function stackUp(bool $withProfile = true): void
{
    foreach (STACK_FORUMS_MIGRATIONS as $name) {
        stackForumsMigration($name)->up();
    }

    stackMessagesMigration()->up();
    stackIgnoredMigration()->up();
    stackPluginsMigration(STACK_PLUGINS_MIGRATION)->up();
    stackPluginsMigration(STACK_PLUGINS_THREAD_MIGRATION)->up();
    AttachmentLinks::install();

    if ($withProfile) {
        foreach ([...STACK_FORUMS_PROFILE, STACK_MESSAGES_PROFILE, STACK_PLUGINS_PROFILE, STACK_ZC_VERSIONS_MIGRATION] as $name) {
            stackProfileMigration($name)->up();
        }
    }
}

/**
 * The tables as zen-cart.com has them: no prefix, migrations not recorded. The prefixed set goes
 * first because some constraint names do not carry the prefix; `restoreStack()` puts it back.
 */
function liveSiteStack(bool $withProfile = true): void
{
    stackDown();
    config(['filagora.table_prefix' => '']);
    stackDown();
    stackUp($withProfile);

    DB::table('migrations')->whereIn('migration', stackMigrationNames())->delete();
}

function restoreStack(): void
{
    if (config('filagora.table_prefix') === '') {
        stackDown();
    }

    config(['filagora.table_prefix' => 'filagora_']);
    stackDown();
    stackUp();
}
