<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Cms;

use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Core\Settings\SettingsRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * The menu link kinds zen-cart.com has besides a plain URL: a legal page (whichever page the
 * privacy and cookie policy settings point at), a named page of the application, and the
 * logout action. A reference that is not on the allow-list resolves to nothing, so the item is
 * dropped. The route names come from `filagora-zencart.cms` because the application owns the routes.
 */
final class SiteLinkTypes
{
    public static function register(NavigationLinkTypes $types): void
    {
        $legal = (array) config('filagora-zencart.cms.legal_pages');
        $pages = (array) config('filagora-zencart.cms.app_pages');
        $actions = (array) config('filagora-zencart.cms.actions');

        $types->register(
            'legal_page',
            static fn (string $reference): ?string => self::legalPageUrl(trim($reference), $legal),
            label: 'Legal page',
            references: self::labels($legal),
        );

        $types->register(
            'app_page',
            static fn (string $reference): ?string => self::appPageUrl(trim($reference), $pages),
            label: 'Application page',
            references: self::labels($pages),
        );

        $types->register(
            'action',
            static fn (string $reference): ?string => array_key_exists(trim($reference), $actions) ? '#' : null,
            keepsPlaceholder: true,
            label: 'Action',
            references: self::labels($actions),
        );
    }

    /**
     * @param  array<string, array{label: string, setting: string, default: string}>  $legal
     */
    private static function legalPageUrl(string $reference, array $legal): ?string
    {
        if (! isset($legal[$reference])) {
            return null;
        }

        $target = trim(app(SettingsRegistry::class)->string($legal[$reference]['setting'], $legal[$reference]['default']));
        $target = $target === '' ? $legal[$reference]['default'] : $target;

        if (str_starts_with($target, '/')) {
            return $target;
        }

        return Route::has('cms.pages.show') ? route('cms.pages.show', ['slug' => $target], false) : '/pages/'.$target;
    }

    /**
     * @param  array<string, array{label: string, route?: string, url?: string, member?: bool}>  $pages
     */
    private static function appPageUrl(string $reference, array $pages): ?string
    {
        $page = $pages[$reference] ?? null;

        if ($page === null) {
            return null;
        }

        if (isset($page['url'])) {
            return url($page['url']);
        }

        $route = $page['route'] ?? '';

        if (! Route::has($route)) {
            return null;
        }

        if ($page['member'] ?? false) {
            $user = auth()->user();

            return $user instanceof Authenticatable ? route($route, $user) : '#';
        }

        return route($route);
    }

    /**
     * @param  array<string, array{label: string}>  $definitions
     * @return array<string, string>
     */
    private static function labels(array $definitions): array
    {
        return array_map(static fn (array $definition): string => $definition['label'], $definitions);
    }
}
