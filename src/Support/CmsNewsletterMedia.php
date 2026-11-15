<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Support;

use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Newsletters\Contracts\NewsletterMedia;

/**
 * The newsletters' images come from the CMS media library: the logo, the image blocks and the
 * rich-text editors name an asset by its number.
 */
final class CmsNewsletterMedia implements NewsletterMedia
{
    public function find(int $id): ?array
    {
        $asset = CmsMediaAsset::query()->find($id);

        if (! $asset instanceof CmsMediaAsset || ! $asset->isImage()) {
            return null;
        }

        return [
            'url' => $asset->publicUrl(),
            'alt' => (string) ($asset->alt_text ?: $asset->filename),
        ];
    }

    public function imageOptions(): array
    {
        return CmsMediaAsset::query()
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('filename')
            ->get()
            ->mapWithKeys(fn (CmsMediaAsset $asset): array => [
                $asset->id => trim($asset->filename.($asset->alt_text ? ' - '.$asset->alt_text : '')),
            ])
            ->all();
    }
}
