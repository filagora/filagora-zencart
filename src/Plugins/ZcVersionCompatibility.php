<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\ZenCart\Models\PluginRelease as ZenCartPluginRelease;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a release is compatible with, from the Zen Cart versions staff ticked when it was
 * uploaded. A leading `v` is ignored when comparing ("v2.1.0" is "2.1.0"). A release that names no
 * version does not match any particular one: it is shown only to someone who has not said which
 * version they run.
 */
final class ZcVersionCompatibility implements Compatibility
{
    public function relations(): array
    {
        return ['zcVersions'];
    }

    public function targets(PluginRelease $release): array
    {
        if (! $release instanceof ZenCartPluginRelease) {
            return [];
        }

        return $release->zcVersions->sortBy('position')->pluck('version')->map(fn ($version): string => (string) $version)->values()->all();
    }

    public function supports(PluginRelease $release, string $target): bool
    {
        $wanted = $this->normalize($target);

        if ($wanted === null) {
            return true;
        }

        foreach ($this->targets($release) as $label) {
            if ($this->normalize($label) === $wanted) {
                return true;
            }
        }

        return false;
    }

    public function restrict(Builder $releases, string $target): void
    {
        $wanted = $this->normalize($target);

        if ($wanted === null) {
            return;
        }

        /** @var Builder<ZenCartPluginRelease> $releases */
        $releases->whereHas('zcVersions', fn (Builder $versions) => $versions->whereIn('version', [$wanted, 'v'.$wanted, 'V'.$wanted]));
    }

    private function normalize(?string $version): ?string
    {
        $version = ltrim(trim((string) $version), 'vV');

        return $version !== '' ? $version : null;
    }
}
