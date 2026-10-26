<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament\Resources\ZcVersions\Pages;

use Filagora\ZenCart\Filament\Resources\ZcVersions\ZcVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListZcVersions extends ListRecords
{
    protected static string $resource = ZcVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
