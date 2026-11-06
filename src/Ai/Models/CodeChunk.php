<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Models;

use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A unique piece of source code (a function, a method, or a window of a procedural file) behind the
 * vector index that gives the AI chat its knowledge of the Zen Cart code.
 *
 * Identical code in several releases is one row: `versions` lists the releases that contain it and
 * CodeChunkLocation says where. It is therefore embedded once, however many versions carry it. The
 * index holds only the id, the repo, the versions and the vector; the text is read from this table.
 *
 * Indexing is explicit (`zencart:ingest-code`), never through model events: ingest writes thousands
 * of rows at a time.
 *
 * @property int $id
 * @property string $content_hash
 * @property string $repo
 * @property string $kind
 * @property string|null $symbol
 * @property string $body
 * @property array<int, string> $versions
 * @property array<int, string> $generations
 * @property Carbon|null $indexed_at
 * @property bool $versions_dirty
 */
class CodeChunk extends Model
{
    protected $fillable = [
        'content_hash', 'repo', 'kind', 'symbol', 'body', 'versions', 'generations', 'indexed_at', 'versions_dirty',
    ];

    public function getTable(): string
    {
        return Tables::name('code_chunks');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'versions' => 'array',
            'generations' => 'array',
            'indexed_at' => 'datetime',
            'versions_dirty' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CodeChunkLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(CodeChunkLocation::class);
    }

    public static function hashFor(string $repo, string $kind, ?string $symbol, string $body): string
    {
        return hash('sha256', implode("\0", [$repo, $kind, (string) $symbol, $body]));
    }

    /**
     * The release line a version belongs to: 1.5.x for v1.5.8a, 2.x for v2.0.1.
     */
    public static function generationFor(string $version): string
    {
        if (preg_match('/^v?(\d+)\.(\d+)/', $version, $parts) !== 1) {
            return 'unknown';
        }

        return (int) $parts[1] === 1 ? "1.{$parts[2]}.x" : "{$parts[1]}.x";
    }
}
