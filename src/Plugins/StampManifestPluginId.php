<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Events\ReleasePublished;

/**
 * A release going live, by approval or because its author may publish at once, gets its plugin id
 * written into the archive's manifest. Switch off with `filagora-zencart.plugins.stamp_manifest_id`.
 */
final class StampManifestPluginId
{
    public function __construct(private readonly ManifestStamper $stamper) {}

    public function handle(ReleasePublished $event): void
    {
        if (! config('filagora-zencart.plugins.stamp_manifest_id', true)) {
            return;
        }

        $this->stamper->stamp($event->release);
    }
}
