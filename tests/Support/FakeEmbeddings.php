<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Tests\Support;

use Filagora\Ai\Contracts\EmbeddingProvider;
use RuntimeException;

/**
 * Three-dimensional vectors chosen by the test, keyed by the first word of the text; anything else
 * gets a vector pointing nowhere in particular. Remembers how many texts each batch held.
 */
class FakeEmbeddings implements EmbeddingProvider
{
    public bool $fails = false;

    /** @var array<string, list<float>> */
    public array $vectors = [];

    /** @var list<int> */
    public array $batches = [];

    public function embed(string $text, ?string $context = null): array
    {
        if ($this->fails) {
            throw new RuntimeException('embeddings are down');
        }

        return $this->vectors[strtok($text, "\n ")] ?? [0.3, 0.3, 0.3];
    }

    public function embedBatch(array $texts, ?string $context = null): array
    {
        $this->batches[] = count($texts);

        return array_map(fn (string $text): array => $this->embed($text), $texts);
    }

    public function batchesCount(): int
    {
        return count($this->batches);
    }

    public function dimensions(): int
    {
        return 3;
    }
}
