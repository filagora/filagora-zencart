<?php

declare(strict_types=1);

namespace Filagora\ZenCart\VersionCheck;

use Illuminate\Http\Request;

/**
 * Reads which plugin a cart is asking about, from the three spellings carts use:
 * `?do=versioncheck&id=N`, `/plugin_check.php/versioncheck/N`, and `/plugincheck/N`.
 */
final class RequestResolver
{
    /**
     * @return array{0: string, 1: ?int} the mode (`versioncheck` when this is a check) and the old library id
     */
    public function resolve(Request $request, ?string $path): array
    {
        $mode = trim((string) $request->query('do', ''));
        $id = (int) $request->query('id', 0);

        if ($mode === 'versioncheck' && $id > 0) {
            return [$mode, $id];
        }

        $pathId = $this->idFromPath($path);

        if ($pathId !== null) {
            return ['versioncheck', $pathId];
        }

        return [$mode, $id > 0 ? $id : null];
    }

    private function idFromPath(?string $path): ?int
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $trimmed = trim($path, '/');

        if (ctype_digit($trimmed)) {
            return (int) $trimmed > 0 ? (int) $trimmed : null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => trim($segment) !== '',
        ));

        foreach ($segments as $index => $segment) {
            if ($segment === 'versioncheck' && isset($segments[$index + 1]) && ctype_digit($segments[$index + 1])) {
                return (int) $segments[$index + 1] > 0 ? (int) $segments[$index + 1] : null;
            }
        }

        return null;
    }
}
