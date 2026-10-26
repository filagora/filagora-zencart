<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Filament;

use Filagora\ZenCart\Filament\Resources\ZcVersions\ZcVersionResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * The staff screens only Zen Cart has: the list of Zen Cart versions a plugin release can say it
 * works with. Register it on the panel next to the plugins package's own plugin:
 * `->plugin(FilagoraZenCartPlugin::make())`.
 */
class FilagoraZenCartPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-zencart';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ZcVersionResource::class]);
    }

    public function boot(Panel $panel): void {}
}
