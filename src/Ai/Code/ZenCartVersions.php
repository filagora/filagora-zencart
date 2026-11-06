<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Code;

use Filagora\ZenCart\Ai\Models\CodeChunk;

/**
 * The Zen Cart versions the code index is built from
 * (`filagora-zencart.code.tags`), and how to point at them on GitHub.
 *
 * A version is a label - what the code is filed under and what threads are
 * matched to. For a release tag the label is the tag; for a moving branch it is
 * a name of our own (`v3.0-dev`) and the config says which branch it reads.
 */
final class ZenCartVersions
{
    private const REPOSITORY_URL = 'https://github.com/zencart/zencart';

    /**
     * label => the tag or branch it is read from.
     *
     * @return array<string, string>
     */
    public function refs(): array
    {
        $refs = [];

        foreach ((array) config('filagora-zencart.code.tags') as $key => $value) {
            $refs[is_int($key) ? (string) $value : (string) $key] = (string) $value;
        }

        return $refs;
    }

    /**
     * @return list<string> labels, oldest first
     */
    public function labels(): array
    {
        $labels = array_keys($this->refs());
        usort($labels, 'strnatcmp');

        return $labels;
    }

    /**
     * The newest version that has actually been released: the tags in the
     * config, not a branch under development (a `label => ref` entry). What a
     * question gets answered from when it does not say which version it is on.
     */
    public function latestReleased(): ?string
    {
        $released = [];

        foreach ((array) config('filagora-zencart.code.tags') as $key => $value) {
            if (is_int($key)) {
                $released[] = (string) $value;
            }
        }

        usort($released, 'strnatcmp');

        return $released === [] ? null : end($released);
    }

    public function isKnown(string $label): bool
    {
        return array_key_exists($label, $this->refs());
    }

    /**
     * @return list<string> release lines (1.5.x, 2.x, 3.x), oldest first
     */
    public function generations(): array
    {
        $generations = array_values(array_unique(array_map(CodeChunk::generationFor(...), $this->labels())));
        usort($generations, 'strnatcmp');

        return $generations;
    }

    public function latestGeneration(): ?string
    {
        $generations = $this->generations();

        return $generations === [] ? null : end($generations);
    }

    /**
     * The GitHub page for lines of a file as they were in a version.
     */
    public function blobUrl(string $label, string $path, int $startLine, int $endLine): string
    {
        $ref = $this->refs()[$label] ?? $label;
        $anchor = $endLine > $startLine ? "#L{$startLine}-L{$endLine}" : "#L{$startLine}";

        return self::REPOSITORY_URL.'/blob/'.rawurlencode($ref).'/'.implode('/', array_map('rawurlencode', explode('/', $path))).$anchor;
    }
}
