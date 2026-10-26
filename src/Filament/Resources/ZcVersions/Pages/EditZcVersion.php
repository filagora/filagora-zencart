<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament\Resources\ZcVersions\Pages;

use Filagora\ZenCart\Filament\Resources\ZcVersions\ZcVersionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditZcVersion extends EditRecord
{
    protected static string $resource = ZcVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
