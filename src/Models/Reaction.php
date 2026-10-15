<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Forums\Models\Reaction as BaseReaction;

/**
 * @property int|null $legacy_reaction_id
 */
class Reaction extends BaseReaction
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['legacy_reaction_id']);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_reaction_id' => 'integer',
        ]);
    }
}
