<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Forums\Models\Forum as BaseForum;

/**
 * @property int|null $legacy_vbulletin_forum_id
 * @property bool $is_plugin_support_forum
 */
class Forum extends BaseForum
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['legacy_vbulletin_forum_id', 'is_plugin_support_forum']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_vbulletin_forum_id' => 'integer',
            'is_plugin_support_forum' => 'boolean',
        ]);
    }
}
