<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Plugins;

use Filagora\Plugins\Contracts\ReleaseExtension;
use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Support\Models;
use Filagora\Plugins\Support\PluginVersion;
use Filagora\ZenCart\Enums\Encapsulated;
use Filagora\ZenCart\Models\ZcVersion;
use Illuminate\Support\Facades\Storage;

/**
 * What a Zen Cart plugin upload adds. The submitter says whether the archive is encapsulated and
 * which Zen Cart versions it works with (`encapsulated` and `zc_versions` in the input's extra
 * values). For an encapsulated archive the install folder must be one the archive makes plain and
 * that no other plugin uses, and the version folder inside it must be the version typed: deployed
 * carts compare that folder against the version check, so a mismatch would mislead every install.
 */
final class ZenCartReleaseExtension implements ReleaseExtension
{
    public function __construct(private readonly ZipInspector $zip) {}

    public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
    {
        $encapsulated = $this->encapsulated($input);
        $this->assertVersionsExist($this->zcVersionIds($input));

        if ($encapsulated !== Encapsulated::YES) {
            return ['encapsulated' => $encapsulated, 'version_folder' => null];
        }

        $inspection = $this->zip->inspect($input->file->getRealPath() ?: $input->file->getPathname());
        $root = $inspection['root'];

        if ($root === null) {
            throw $this->refuse('An encapsulated plugin archive must resolve to one install folder. '.$inspection['reason']);
        }

        $existing = $this->zip->normalizeRoot($plugin?->getAttribute('zip_root'));

        if ($existing !== null && $existing !== $root) {
            throw $this->refuse('Encapsulated releases of this plugin must keep the existing install folder "'.$existing.'".');
        }

        if ($this->claimedByAnother($plugin, $root)) {
            throw $this->refuse('The install folder "'.$root.'" is already used by another plugin.');
        }

        $folder = $inspection['version_folder'];

        if ($folder !== null && PluginVersion::canonical($folder) !== $canonicalVersion) {
            throw $this->refuse('The version typed ('.$canonicalVersion.') does not match the version folder in the archive ('.(PluginVersion::canonical($folder) ?? $folder).').');
        }

        return ['encapsulated' => $encapsulated, 'version_folder' => $folder];
    }

    public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void
    {
        $ids = $this->zcVersionIds($input);

        if ($ids !== [] && method_exists($release, 'zcVersions')) {
            $release->zcVersions()->sync($ids);
        }

        if ($this->encapsulated($input) === Encapsulated::YES && $this->zip->normalizeRoot($plugin->getAttribute('zip_root')) === null) {
            $this->adoptRoot($plugin, $release);
        }
    }

    private function adoptRoot(Plugin $plugin, PluginRelease $release): void
    {
        $disk = Storage::disk((string) config('filagora-plugins.storage.disk'));

        if (! $disk->exists($release->zip_path)) {
            return;
        }

        $root = $this->zip->inspect($disk->path($release->zip_path))['root'];

        if ($root === null || $this->claimedByAnother($plugin, $root)) {
            return;
        }

        $plugin->timestamps = false;
        $plugin->forceFill(['zip_root' => $root])->saveQuietly();
    }

    private function claimedByAnother(?Plugin $plugin, string $root): bool
    {
        return Models::class('plugin')::query()
            ->when($plugin !== null, fn ($query) => $query->whereKeyNot($plugin->getKey()))
            ->where('zip_root', $root)
            ->exists();
    }

    private function encapsulated(ReleaseInput $input): Encapsulated
    {
        $value = $input->extra['encapsulated'] ?? Encapsulated::UNKNOWN->value;
        $value = $value instanceof Encapsulated ? $value : Encapsulated::tryFrom((string) $value);

        return $value ?? throw $this->refuse('Say whether the archive is encapsulated: yes, no or unknown.');
    }

    /**
     * @return list<int>
     */
    private function zcVersionIds(ReleaseInput $input): array
    {
        return array_values(array_unique(array_map('intval', (array) ($input->extra['zc_versions'] ?? []))));
    }

    /**
     * @param  list<int>  $ids
     */
    private function assertVersionsExist(array $ids): void
    {
        if ($ids !== [] && ZcVersion::query()->whereKey($ids)->count() !== count($ids)) {
            throw $this->refuse('One of the Zen Cart versions chosen does not exist.');
        }
    }

    private function refuse(string $detail): ReleaseRefused
    {
        return new ReleaseRefused(ReleaseRefusal::REJECTED_BY_EXTENSION, $detail);
    }
}
