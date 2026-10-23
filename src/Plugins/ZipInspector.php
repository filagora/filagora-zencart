<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use ZipArchive;

/**
 * Reads the layout of a plugin archive without extracting it: the folder the plugin installs
 * under, and, in the layout Zen Cart 1.5.7 and later install from (`zc_plugins/<key>/<version>/`),
 * the literal version folder. That folder is what every deployed cart compares against a
 * version-check answer, so it is returned exactly as written.
 */
final class ZipInspector
{
    /**
     * @return array{root: string|null, reason: string, version_folder: string|null}
     */
    public function inspect(string $absolutePath): array
    {
        if (! class_exists(ZipArchive::class) || ! is_file($absolutePath)) {
            return $this->none('The ZIP file is unreadable.');
        }

        // Read-only: closing an archive opened for writing can rewrite or, for an invalid file,
        // delete it.
        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return $this->none('The ZIP archive could not be opened.');
        }

        try {
            $manifest = $this->manifestEntries($zip);

            if ($manifest['pluginKey'] !== null) {
                return ['root' => $manifest['pluginKey'], 'reason' => 'OK', 'version_folder' => $manifest['versionFolder']];
            }

            // Manifests that name no single plugin are not a reason to look at the top folder,
            // which in this layout is always `zc_plugins`.
            if ($manifest['found'] > 0) {
                return $this->none('The archive layout is ambiguous.');
            }

            return $this->rootFromFolders($zip);
        } finally {
            $zip->close();
        }
    }

    public function normalizeRoot(?string $root): ?string
    {
        $root = strtolower(trim((string) $root, " \t\n\r\0\x0B/\\"));

        return $root !== '' ? $root : null;
    }

    /**
     * @return array{root: string|null, reason: string, version_folder: string|null}
     */
    private function rootFromFolders(ZipArchive $zip): array
    {
        $roots = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $this->entryName($zip, $index);

            if ($entry === null || ! str_contains($entry, '/')) {
                continue;
            }

            $root = $this->normalizeRoot(explode('/', $entry, 2)[0]);

            if ($root === null || $root === '__macosx') {
                continue;
            }

            if ($root === 'zc_plugins') {
                return $this->none('The archive has a zc_plugins folder but no manifest naming the plugin.');
            }

            $roots[$root] = true;

            if (count($roots) > 1) {
                return $this->none('The archive layout is ambiguous.');
            }
        }

        $root = array_key_first($roots);

        return is_string($root) && $root !== ''
            ? ['root' => $root, 'reason' => 'OK', 'version_folder' => null]
            : $this->none('No install folder could be worked out from the archive.');
    }

    /**
     * Every `manifest.php` names a plugin key (and, in the versioned layout, a version folder).
     * Both are null unless the archive resolves to exactly one of each.
     *
     * @return array{pluginKey: string|null, versionFolder: string|null, found: int}
     */
    private function manifestEntries(ZipArchive $zip): array
    {
        $keys = [];
        $folders = [];
        $found = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $this->entryName($zip, $index);

            if ($entry === null || (! str_ends_with(strtolower($entry), '/manifest.php') && strtolower($entry) !== 'manifest.php')) {
                continue;
            }

            $found++;
            $segments = array_values(array_filter(explode('/', $entry), static fn (string $segment): bool => $segment !== ''));
            array_pop($segments);

            if (($segments[0] ?? null) === 'zc_plugins') {
                array_shift($segments);
            }

            $candidate = $segments === [] ? '' : (string) end($segments);

            if ($candidate === '') {
                continue;
            }

            $folder = null;

            if ($this->looksLikeVersion($candidate) && count($segments) >= 2) {
                $folder = $candidate;
                $candidate = $segments[count($segments) - 2];
            }

            $key = $this->normalizeRoot($candidate);

            if ($key !== null) {
                $keys[$key] = true;

                // Ambiguous already: stop, rather than scan every entry of a padded archive.
                if (count($keys) > 1) {
                    return ['pluginKey' => null, 'versionFolder' => null, 'found' => $found];
                }
            }

            if ($folder !== null) {
                $folders[$folder] = true;
            }
        }

        return [
            'pluginKey' => count($keys) === 1 ? array_key_first($keys) : null,
            'versionFolder' => count($folders) === 1 ? array_key_first($folders) : null,
            'found' => $found,
        ];
    }

    private function looksLikeVersion(string $segment): bool
    {
        return preg_match('/^v?[._-]?\d+(?:\.\d+)*(?:[-_.]?[a-z0-9]+)*$/i', $segment) === 1;
    }

    private function entryName(ZipArchive $zip, int $index): ?string
    {
        $name = $zip->getNameIndex($index);

        if (! is_string($name) || $name === '') {
            return null;
        }

        $name = ltrim(str_replace('\\', '/', $name), '/');

        return $name !== '' ? $name : null;
    }

    /**
     * @return array{root: null, reason: string, version_folder: null}
     */
    private function none(string $reason): array
    {
        return ['root' => null, 'reason' => $reason, 'version_folder' => null];
    }
}
