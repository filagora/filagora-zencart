<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Enums;

/**
 * Whether a release's archive keeps its files in a folder of their own (the layout Zen Cart 1.5.7
 * and later install plugins from). The values are the ones the live table stores.
 */
enum Encapsulated: string
{
    case UNKNOWN = 'unknown';
    case YES = 'yes';
    case NO = 'no';

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Unknown',
            self::YES => 'Yes',
            self::NO => 'No',
        };
    }
}
