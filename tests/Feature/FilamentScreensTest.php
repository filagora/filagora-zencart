<?php

declare(strict_types=1);

use Filagora\Plugins\Filament\ReleaseScreens;
use Filagora\ZenCart\Filament\FilagoraZenCartPlugin;
use Filagora\ZenCart\Filament\Resources\ZcVersions\ZcVersionResource;
use Filament\Panel;

it('adds the Zen Cart versions screen to a panel', function () {
    $panel = Panel::make()->id('test')->plugin(FilagoraZenCartPlugin::make());

    expect($panel->getResources())->toContain(ZcVersionResource::class)
        ->and(FilagoraZenCartPlugin::make()->getId())->toBe('filagora-zencart');
});

it('has list, create and edit pages for the versions', function () {
    expect(array_keys(ZcVersionResource::getPages()))->toBe(['index', 'create', 'edit']);
});

it('adds the encapsulated and Zen Cart version columns and filters to the plugin release list', function () {
    $screens = app(ReleaseScreens::class);

    $columns = array_map(fn ($column) => $column->getName(), $screens->columns());
    $filters = array_map(fn ($filter) => $filter->getName(), $screens->filters());

    expect($columns)->toContain('encapsulated', 'zcVersions.version')
        ->and($filters)->toContain('encapsulated', 'zc_version');
});
