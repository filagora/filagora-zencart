<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Forums\Models\Thread as BaseThread;

/**
 * @property int|null $legacy_vbulletin_thread_id
 */
class Thread extends BaseThread
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['legacy_vbulletin_thread_id']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_vbulletin_thread_id' => 'integer',
        ]);
    }
}
