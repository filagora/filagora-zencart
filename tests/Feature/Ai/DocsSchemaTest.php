<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Illuminate\Support\Facades\DB;

it('owns the documentation table', function () {
    expect(array_keys(app(SchemaRegistry::class)->get('zencart-ai')->tables))->toBe(['doc_pages']);
});

it('builds the table exactly as the snapshot of the live one has it', function () {
    foreach (app(SchemaRegistry::class)->get('zencart-ai')->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('adopts the table by recording its migration', function () {
    $schema = app(SchemaRegistry::class)->get('zencart-ai');
    DB::table('migrations')->whereIn('migration', $schema->migrationNames())->delete();

    expect(app(Adopter::class)->planFor($schema)->status)->toBe(AdoptionStatus::Adoptable);

    $this->artisan('filagora:adopt', ['--package' => 'zencart-ai'])->assertExitCode(0);

    expect(app(Adopter::class)->planFor($schema)->status)->toBe(AdoptionStatus::AlreadyAdopted);
});
