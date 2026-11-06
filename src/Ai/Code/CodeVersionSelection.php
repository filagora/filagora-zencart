<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Code;

use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Equals;

/**
 * Which Zen Cart code a question is about.
 *
 * `version` is an exact label when the text named one we have (1.5.8a); null
 * when only the release line is known (1.5.9 -> the 1.5.x line, from whichever
 * of its versions is newest). `assumed` is true when the text named nothing and
 * the latest release was taken instead - the answer must say so.
 */
final readonly class CodeVersionSelection
{
    public function __construct(
        public ?string $version,
        public string $generation,
        public bool $assumed,
    ) {}

    /**
     * The filter for this selection on the code_chunks index.
     */
    public function filter(): Filter
    {
        return new All(
            new Equals('repo', 'zencart'),
            $this->version !== null ? new Equals('versions', $this->version) : new Equals('generations', $this->generation),
        );
    }

    public function describe(): string
    {
        return $this->version ?? "the {$this->generation} line";
    }
}
