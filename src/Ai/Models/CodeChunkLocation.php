<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Models;

use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a CodeChunk sits in one version of the repository.
 *
 * @property int $id
 * @property int $code_chunk_id
 * @property string $version
 * @property string $path
 * @property int $start_line
 * @property int $end_line
 */
class CodeChunkLocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['code_chunk_id', 'version', 'path', 'start_line', 'end_line'];

    public function getTable(): string
    {
        return Tables::name('code_chunk_locations');
    }

    /**
     * @return BelongsTo<CodeChunk, $this>
     */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(CodeChunk::class, 'code_chunk_id');
    }
}
