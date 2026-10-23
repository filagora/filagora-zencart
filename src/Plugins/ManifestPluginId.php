<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use ZipArchive;

/**
 * Writes a plugin's id into the `manifest.php` inside its stored archive. A cart reads that number
 * from the file to ask the version check about the plugin, so it has to be the id the version check
 * knows. The manifest is only read as text, never run, and an archive that does not have exactly
 * one manifest declaring a `pluginId` is left alone.
 */
final class ManifestPluginId
{
    /**
     * @return bool Whether the archive was changed.
     */
    public function stamp(string $absolutePath, int $pluginId): bool
    {
        if ($pluginId <= 0 || ! class_exists(ZipArchive::class) || ! is_file($absolutePath)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return false;
        }

        try {
            $entry = $this->manifestEntry($zip);

            if ($entry === null) {
                return false;
            }

            $contents = $zip->getFromName($entry);

            if (! is_string($contents)) {
                return false;
            }

            $updated = $this->withPluginId($contents, $pluginId);

            return $updated !== null && $updated !== $contents && $zip->addFromString($entry, $updated);
        } finally {
            $zip->close();
        }
    }

    /**
     * The `pluginId` a manifest declares, or null.
     */
    public function read(string $absolutePath): ?int
    {
        if (! class_exists(ZipArchive::class) || ! is_file($absolutePath)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $entry = $this->manifestEntry($zip);
            $contents = $entry === null ? false : $zip->getFromName($entry);

            return is_string($contents) && preg_match(self::PATTERN, $contents, $found) === 1 ? (int) $found[3] : null;
        } finally {
            $zip->close();
        }
    }

    private const PATTERN = '/([\'"])pluginId\1(\s*=>\s*)(-?\d+)/';

    private function withPluginId(string $contents, int $pluginId): ?string
    {
        if (preg_match(self::PATTERN, $contents) !== 1) {
            return null;
        }

        return preg_replace(self::PATTERN, '${1}pluginId${1}${2}'.$pluginId, $contents, 1);
    }

    /**
     * The one `manifest.php` in the archive; null for none or several.
     */
    private function manifestEntry(ZipArchive $zip): ?string
    {
        $found = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (! is_string($name) || $name === '') {
                continue;
            }

            $normalized = strtolower(ltrim(str_replace('\\', '/', $name), '/'));

            if ($normalized === 'manifest.php' || str_ends_with($normalized, '/manifest.php')) {
                $found[] = $name;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
