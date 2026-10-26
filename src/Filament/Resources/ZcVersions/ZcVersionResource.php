<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament\Resources\ZcVersions;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Plugins\Support\Gates;
use Filagora\ZenCart\Filament\Resources\ZcVersions\Pages\CreateZcVersion;
use Filagora\ZenCart\Filament\Resources\ZcVersions\Pages\EditZcVersion;
use Filagora\ZenCart\Filament\Resources\ZcVersions\Pages\ListZcVersions;
use Filagora\ZenCart\Filament\Resources\ZcVersions\Schemas\ZcVersionForm;
use Filagora\ZenCart\Filament\Resources\ZcVersions\Tables\ZcVersionsTable;
use Filagora\ZenCart\Models\ZcVersion;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ZcVersionResource extends Resource
{
    use GatedByAbility;

    protected static ?string $model = ZcVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 45;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Gates::MODERATE;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('plugins');
    }

    public static function getNavigationLabel(): string
    {
        return 'Zen Cart versions';
    }

    public static function getModelLabel(): string
    {
        return 'Zen Cart version';
    }

    public static function form(Schema $schema): Schema
    {
        return ZcVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ZcVersionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZcVersions::route('/'),
            'create' => CreateZcVersion::route('/create'),
            'edit' => EditZcVersion::route('/{record}/edit'),
        ];
    }
}
