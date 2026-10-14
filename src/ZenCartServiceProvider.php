<?php

declare(strict_types=1);

namespace Filagora\ZenCart;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Entitlements\Contracts\SuperUserCheck;
use Filagora\Forums\Contracts\ContentModerationPolicy;
use Filagora\Forums\Contracts\PaidAccessCheck;
use Filagora\Forums\Contracts\PostSignatureResolver;
use Filagora\ZenCart\Cms\SiteBlockTypes;
use Filagora\ZenCart\Cms\SiteLinkTypes;
use Filagora\ZenCart\Models\Forum;
use Filagora\ZenCart\Models\Post;
use Filagora\ZenCart\Models\Thread;
use Filagora\ZenCart\Support\MembershipPaidAccess;
use Filagora\ZenCart\Support\RoleSuperUserCheck;
use Filagora\ZenCart\Support\UserFlagsModerationPolicy;
use Filagora\ZenCart\Support\UserSignatureResolver;
use Illuminate\Support\ServiceProvider;

class ZenCartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-zencart.php', 'filagora-zencart');

        $this->app->bind(SuperUserCheck::class, RoleSuperUserCheck::class);
        $this->app->bind(PaidAccessCheck::class, MembershipPaidAccess::class);
        $this->app->bind(ContentModerationPolicy::class, UserFlagsModerationPolicy::class);
        $this->app->bind(PostSignatureResolver::class, UserSignatureResolver::class);

        $this->useZenCartModels();
        $this->remapGates();
        $this->extendCms();
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('zencart', 'Filagora Zen Cart profile', 'filagora/filagora-zencart', requires: ['core', 'entitlements', 'forums', 'cms']),
        );

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'zencart',
            'forums',
            __DIR__.'/../database/extensions/forums.php',
            __DIR__.'/../database/migrations',
        ));

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'zencart',
            'cms',
            __DIR__.'/../database/extensions/cms.php',
            __DIR__.'/../database/migrations/cms',
        ));

        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/cms']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-zencart.php' => config_path('filagora-zencart.php'),
            ], 'filagora-zencart-config');
        }
    }

    /**
     * The site's own block types and their old names, and its menu link kinds, added to the
     * cms package when it first builds its registries.
     */
    private function extendCms(): void
    {
        $this->callAfterResolving(BlockTypeRegistry::class, function (BlockTypeRegistry $registry): void {
            foreach (SiteBlockTypes::all() as $type) {
                $registry->register($type);
            }

            foreach (SiteBlockTypes::ALIASES as $legacy => $canonical) {
                $registry->alias($legacy, $canonical);
            }
        });

        $this->callAfterResolving(NavigationLinkTypes::class, SiteLinkTypes::register(...));
    }

    /**
     * Point the forum models at the subclasses that know the extra columns. `filagora.models`
     * wins over the package's own map, and a host that set its own class is left alone.
     */
    private function useZenCartModels(): void
    {
        foreach (['forum' => Forum::class, 'thread' => Thread::class, 'post' => Post::class] as $key => $class) {
            if (config("filagora.models.{$key}") === null) {
                config(["filagora.models.{$key}" => $class]);
            }
        }
    }

    /**
     * Gate abilities to `operator.*` entitlement keys. Set during register so that the
     * packages, which read their `gates` config when they boot, see them whichever provider
     * registered first.
     */
    private function remapGates(): void
    {
        foreach ((array) config('filagora-zencart.gates') as $key => $map) {
            config([$key => $map]);
        }
    }
}
