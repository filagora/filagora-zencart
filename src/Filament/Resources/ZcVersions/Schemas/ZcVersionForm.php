<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament\Resources\ZcVersions\Schemas;

use Filagora\ZenCart\Models\ZcVersion;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ZcVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('version')
                ->required()
                ->maxLength(40)
                ->unique(table: (new ZcVersion)->getTable(), ignoreRecord: true)
                ->helperText('For example v2.1.0. Carts read it with the leading v.'),
            TextInput::make('position')
                ->required()
                ->numeric()
                ->default(0)
                ->helperText('Order in the lists, lowest first.'),
            Toggle::make('is_active')
                ->default(true)
                ->helperText('Staff offer an active version when a release is uploaded.'),
        ]);
    }
}
