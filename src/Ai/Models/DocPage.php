<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Models;

use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A page of the Zen Cart documentation, imported by `docs:import`. Every page is public documentation,
 * so unlike forum content nothing about who is asking decides whether it may be shown.
 *
 * @property int $id
 * @property string $source_file
 * @property string $url_path
 * @property string $title
 * @property string|null $description
 * @property string|null $category
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocPage extends Model
{
    protected $fillable = [
        'source_file',
        'url_path',
        'title',
        'description',
        'category',
        'body',
    ];

    public function getTable(): string
    {
        return Tables::name('doc_pages');
    }
}
