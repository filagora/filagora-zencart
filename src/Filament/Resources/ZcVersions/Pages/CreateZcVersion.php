<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament\Resources\ZcVersions\Pages;

use Filagora\ZenCart\Filament\Resources\ZcVersions\ZcVersionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateZcVersion extends CreateRecord
{
    protected static string $resource = ZcVersionResource::class;
}
