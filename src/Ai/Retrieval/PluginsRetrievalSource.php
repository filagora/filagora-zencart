<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Retrieval;

use Filagora\Ai\Contracts\RetrievalSource;
use Filagora\Ai\Retrieval\RetrievalQuery;
use Filagora\Ai\Services\ChatRetrieval;
use Filagora\Ai\Support\Embeddings;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Filagora\ZenCart\Ai\Search\PluginReleaseVectorIndex;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Plugins as something the chat can look in: the live releases closest in meaning to the message,
 * offered as the plugin's page with the release's changelog (or the plugin's description) as the excerpt.
 * Only live releases of live plugins are offered, whatever the index still holds.
 */
final class PluginsRetrievalSource implements RetrievalSource
{
    private const EXCERPT_LENGTH = 500;

    public function __construct(private readonly Search $search) {}

    public function key(): string
    {
        return 'plugins';
    }

    public function retrieve(RetrievalQuery $query): array
    {
        if (Embeddings::dimensions() === null) {
            return [];
        }

        $limit = $query->limitFor($this->key(), ChatRetrieval::defaultLimit());
        $ids = array_map('intval', $this->search->search(PluginReleaseVectorIndex::NAME, new SearchQuery(filter: new Equals('status', PluginStatus::ACTIVE->value), vector: $query->vector, perPage: $limit))->ids());

        if ($ids === []) {
            return [];
        }

        $releases = Models::class('release')::query()->whereIn('id', $ids)->with('plugin')->get()->keyBy('id');
        $items = [];

        foreach ($ids as $id) {
            $release = $releases->get($id);

            if (! $release instanceof PluginRelease || $release->status !== PluginStatus::ACTIVE) {
                continue;
            }

            $plugin = $release->plugin;

            if (! $plugin instanceof Plugin || $plugin->status !== PluginStatus::ACTIVE || ($url = $this->link($plugin)) === '') {
                continue;
            }

            $items[] = [
                'type' => 'plugin_release',
                'id' => $release->id,
                'title' => (string) $plugin->name,
                'url' => $url,
                'excerpt' => Str::limit((string) ($release->changelog ?: $plugin->description), self::EXCERPT_LENGTH),
            ];
        }

        return $items;
    }

    /**
     * The plugin's public page, by the host's route name (as the version check names it).
     */
    private function link(Plugin $plugin): string
    {
        $route = (string) (config('filagora-zencart.versioncheck.plugin_route') ?? config('filagora-plugins.routes.plugin') ?? 'plugins.show');

        return Route::has($route) ? (string) route($route, $plugin) : '';
    }
}
