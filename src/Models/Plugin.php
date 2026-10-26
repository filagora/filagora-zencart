<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Plugins\Models\Plugin as BasePlugin;

/**
 * @property int|null $legacy_vbulletin_file_id The number deployed carts ask the version check about.
 * @property string|null $zip_root The folder the plugin installs under, unique across plugins.
 */
class Plugin extends BasePlugin
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['legacy_vbulletin_file_id', 'zip_root']);

        parent::__construct($attributes);
    }

    protected static function booted(): void
    {
        // A plugin with no legacy id is known to carts by its own row key. When an imported plugin
        // already holds that number as its legacy id the version check would answer for the wrong
        // plugin, so the new row is given an unused id instead.
        static::created(function (self $plugin): void {
            if ($plugin->legacy_vbulletin_file_id !== null || ! static::query()->where('legacy_vbulletin_file_id', $plugin->getKey())->exists()) {
                return;
            }

            $plugin->legacy_vbulletin_file_id = max(
                (int) static::query()->max('id'),
                (int) static::query()->max('legacy_vbulletin_file_id'),
            ) + 1;
            $plugin->save();
        });
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_vbulletin_file_id' => 'integer',
        ]);
    }

    /**
     * The number a cart knows this plugin by: its old library id, or its own id when it never had one.
     */
    public function versionCheckId(): int
    {
        return (int) ($this->legacy_vbulletin_file_id ?? $this->getKey());
    }
}
