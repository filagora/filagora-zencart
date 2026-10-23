<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Core\Support\Tables;
use Filagora\Plugins\Models\PluginRelease as BasePluginRelease;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A Zen Cart version a plugin release can say it works with.
 *
 * @property int $id
 * @property string $version For example "v2.1.0".
 * @property bool $is_active Whether staff offer it when a release is uploaded.
 * @property int $position Order in the lists, lowest first.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ZcVersion extends Model
{
    protected $fillable = ['version', 'is_active', 'position'];

    public function getTable(): string
    {
        return Tables::name('plugin_zc_versions');
    }

    public function releasesTable(): string
    {
        return Tables::name('plugin_release_zc_versions');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<BasePluginRelease, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Models::class('release'), $this->releasesTable(), 'plugin_zc_version_id', 'plugin_release_id')->withTimestamps();
    }
}
