<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Console;

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\ZenCart\Plugins\ManifestPluginId;
use Filagora\ZenCart\Plugins\ManifestStamper;
use Illuminate\Console\Command;

/**
 * Brings the plugin id inside every live release's archive into line with the id the version
 * check knows. Archives with no single manifest declaring a `pluginId` are reported and left alone.
 */
class StampManifestIdsCommand extends Command
{
    protected $signature = 'filagora:zencart:stamp-manifest-ids {--plugin= : Only this plugin (its slug)} {--dry-run : Report what would change, change nothing}';

    protected $description = 'Write each live release\'s plugin id into the manifest of its stored archive';

    public function handle(ManifestStamper $stamper, ManifestPluginId $manifest): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['checked' => 0, 'already right' => 0, 'stamped' => 0, 'no manifest id' => 0, 'missing file' => 0];

        $releases = Models::class('release')::query()
            ->with('plugin')
            ->where('status', PluginStatus::ACTIVE->value)
            ->when($this->option('plugin'), fn ($query, $slug) => $query->whereHas('plugin', fn ($plugins) => $plugins->where('slug', $slug)))
            ->orderBy('id');

        $releases->each(function (PluginRelease $release) use ($stamper, $manifest, $dry, &$counts): void {
            $counts['checked']++;
            $path = $stamper->archivePath($release);

            if ($path === null) {
                $counts['missing file']++;

                return;
            }

            $current = $manifest->read($path);

            if ($current === null) {
                $counts['no manifest id']++;

                return;
            }

            if ($current === $stamper->expectedId($release)) {
                $counts['already right']++;

                return;
            }

            if (! $dry && $stamper->stamp($release)) {
                $counts['stamped']++;
            } elseif ($dry) {
                $counts['stamped']++;
            }
        });

        foreach ($counts as $label => $count) {
            $this->line(sprintf('%s: %d', ucfirst($label), $count));
        }

        $this->info($dry ? 'Dry run: nothing was changed.' : 'Done.');

        return self::SUCCESS;
    }
}
