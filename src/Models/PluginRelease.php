<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Plugins\Models\PluginRelease as BasePluginRelease;
use Filagora\ZenCart\Enums\Encapsulated;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string|null $version_folder The folder named after the version inside an encapsulated archive.
 * @property Encapsulated $encapsulated
 */
class PluginRelease extends BasePluginRelease
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['version_folder', 'encapsulated']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'encapsulated' => Encapsulated::class,
        ]);
    }

    /**
     * The Zen Cart versions this release works with.
     *
     * @return BelongsToMany<ZcVersion, $this>
     */
    public function zcVersions(): BelongsToMany
    {
        return $this->belongsToMany(ZcVersion::class, (new ZcVersion)->releasesTable(), 'plugin_release_id', 'plugin_zc_version_id')->withTimestamps();
    }
}
