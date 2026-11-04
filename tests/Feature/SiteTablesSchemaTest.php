<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * These run under the test prefix, not as live-style tables: `users`, `cache` and `jobs` would
 * collide with the test application's own.
 */

afterEach(function () {
    if (Schema::hasColumn(Tables::name('contacts'), 'note')) {
        Schema::table(Tables::name('contacts'), fn ($table) => $table->dropColumn('note'));
    }
});

it('owns every table the site used to create', function () {
    expect(array_keys(app(SchemaRegistry::class)->get('zencart-site')->tables))->toEqualCanonicalizing([
        'users', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'notifications', 'personal_access_tokens', 'newsletter_subscribers', 'newsletters', 'newsletter_sends',
        'newsletter_events', 'newsletter_blocks', 'newsletter_link_clicks', 'webhook_events', 'queue_monitors',
        'registration_email_send_counters', 'bug_reports', 'legacy_import_user_maps', 'contacts', 'roles', 'model_has_roles',
    ]);
});

it('builds every site table exactly as the snapshot of the live tables has it', function () {
    foreach (app(SchemaRegistry::class)->get('zencart-site')->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected))->toBe([], $base);
    }
});

it('seeds the shared newsletter blocks on a fresh database', function () {
    expect(DB::table(Tables::name('newsletter_blocks'))->pluck('key')->all())->toEqualCanonicalizing(['shared_header', 'shared_footer']);
});

it('adopts the site tables by recording their migrations', function () {
    $schema = app(SchemaRegistry::class)->get('zencart-site');
    DB::table('migrations')->whereIn('migration', $schema->migrationNames())->delete();

    expect(app(Adopter::class)->planFor($schema)->status)->toBe(AdoptionStatus::Adoptable);

    $this->artisan('filagora:adopt', ['--package' => 'zencart-site'])->assertExitCode(0);

    expect(app(Adopter::class)->planFor($schema)->status)->toBe(AdoptionStatus::AlreadyAdopted)
        ->and(DB::table('migrations')->whereIn('migration', $schema->migrationNames())->count())->toBe(count($schema->migrationNames()));
});

it('refuses to adopt when a site table has drifted from the snapshot', function () {
    Schema::table(Tables::name('contacts'), fn ($table) => $table->string('note')->nullable());

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('zencart-site'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and(implode(' ', $plan->differences))->toContain('contacts')->toContain('note');
});
