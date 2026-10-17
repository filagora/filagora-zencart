<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Models;

use Filagora\Forums\Models\PostAttachment as BasePostAttachment;
use Illuminate\Support\Carbon;

/**
 * @property int|null $legacy_attachment_id
 * @property int|null $legacy_filedata_id
 * @property int|null $legacy_post_id
 * @property int|null $legacy_pmtext_id
 * @property string|null $legacy_state
 * @property int $legacy_download_count
 * @property Carbon|null $legacy_created_at
 */
class PostAttachment extends BasePostAttachment
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable([
            'legacy_attachment_id',
            'legacy_filedata_id',
            'legacy_post_id',
            'legacy_pmtext_id',
            'legacy_state',
            'legacy_download_count',
            'legacy_created_at',
        ]);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'legacy_attachment_id' => 'integer',
            'legacy_filedata_id' => 'integer',
            'legacy_post_id' => 'integer',
            'legacy_pmtext_id' => 'integer',
            'legacy_download_count' => 'integer',
            'legacy_created_at' => 'datetime',
        ]);
    }
}
