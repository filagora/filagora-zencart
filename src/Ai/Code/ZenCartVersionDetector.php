<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Code;

use Filagora\ZenCart\Ai\Models\CodeChunk;

/**
 * Finds the Zen Cart version a piece of text is about ("Zen Cart 1.5.8a",
 * "v2.0.1", "ZC158").
 *
 * It looks only for what is unambiguous. A bare "2.1.0" or "3.5.1" is as likely
 * to be jQuery or a PHP library as Zen Cart, so those count only when "Zen Cart",
 * "ZC" or "version" stands just before them; the 1.5.x numbers are distinctive
 * enough to count on their own.
 */
final class ZenCartVersionDetector
{
    private const NUMBER = '(?<number>v?\d+\.\d+(?:\.\d+)?[a-d]?)';

    public function __construct(private readonly ZenCartVersions $versions) {}

    /**
     * The version the first text that names one is about; later texts are
     * only consulted if the earlier ones name nothing.
     */
    public function detect(string ...$texts): ?CodeVersionSelection
    {
        foreach ($texts as $text) {
            // Every candidate in turn: "PHP version 8.1 on Zen Cart 2.0.1"
            // has a number after "version" that is not ours.
            foreach ($this->candidates($text) as $label) {
                $selection = $this->selectionFor($label);

                if ($selection !== null) {
                    return $selection;
                }
            }
        }

        return null;
    }

    /**
     * What to search when nothing names a version: the latest release, never a
     * branch still under development. Only if no release is configured is the
     * newest line used instead.
     */
    public function assumed(): ?CodeVersionSelection
    {
        $released = $this->versions->latestReleased();

        if ($released !== null) {
            return new CodeVersionSelection($released, CodeChunk::generationFor($released), true);
        }

        $generation = $this->versions->latestGeneration();

        return $generation === null ? null : new CodeVersionSelection(null, $generation, true);
    }

    private function selectionFor(string $label): ?CodeVersionSelection
    {
        if ($this->versions->isKnown($label)) {
            return new CodeVersionSelection($label, CodeChunk::generationFor($label), false);
        }

        // 1.5.9, 2.3.0: a version we do not hold, but whose line we do.
        $generation = CodeChunk::generationFor($label);

        return in_array($generation, $this->versions->generations(), true)
            ? new CodeVersionSelection(null, $generation, false)
            : null;
    }

    /**
     * @return list<string> labels the text may be naming, most certain first
     */
    private function candidates(string $text): array
    {
        $candidates = [];

        // ZC158, zc157c
        if (preg_match_all('/\bzc[\s_-]?1(?<minor>5)(?<patch>\d)(?<letter>[a-d]?)\b/i', $text, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $candidates[] = strtolower("v1.{$match['minor']}.{$match['patch']}{$match['letter']}");
            }
        }

        $patterns = [
            '/\b(?<number>v?1\.5\.\d[a-d]?)\b/i',
            // v2.0.1 on its own: only the 2.x numbers, since "v3.5.1" is as likely to be jQuery.
            '/\b(?<number>v2\.[0-3]\.\d+[a-d]?)\b/i',
            '/(?:zen[\s-]?cart|\bzc|\bversion)\b\D{0,20}?'.self::NUMBER.'(?![\d.]*\d)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $match) {
                    $number = strtolower($match['number']);
                    $candidates[] = str_starts_with($number, 'v') ? $number : 'v'.$number;
                }
            }
        }

        return array_values(array_unique($candidates));
    }
}
