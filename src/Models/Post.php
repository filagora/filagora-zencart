<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Forums\Models\Post as BasePost;

/**
 * @property int|null $legacy_post_id
 */
class Post extends BasePost
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['legacy_post_id']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_post_id' => 'integer',
        ]);
    }
}
