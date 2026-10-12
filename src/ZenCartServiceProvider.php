<?php

declare(strict_types=1);

namespace ForumKit\ZenCart;

use ForumKit\Core\Modules\Module;
use ForumKit\Core\Modules\ModuleRegistry;
use ForumKit\Core\Schema\SchemaExtension;
use ForumKit\Core\Schema\SchemaRegistry;
use ForumKit\Entitlements\Contracts\SuperUserCheck;
use ForumKit\Forums\Contracts\ContentModerationPolicy;
use ForumKit\Forums\Contracts\PaidAccessCheck;
use ForumKit\Forums\Contracts\PostSignatureResolver;
use ForumKit\ZenCart\Models\Forum;
use ForumKit\ZenCart\Models\Post;
use ForumKit\ZenCart\Models\Thread;
use ForumKit\ZenCart\Support\MembershipPaidAccess;
use ForumKit\ZenCart\Support\RoleSuperUserCheck;
use ForumKit\ZenCart\Support\UserFlagsModerationPolicy;
use ForumKit\ZenCart\Support\UserSignatureResolver;
use Illuminate\Support\ServiceProvider;

class ZenCartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/forumkit-zencart.php', 'forumkit-zencart');

        $this->app->bind(SuperUserCheck::class, RoleSuperUserCheck::class);
        $this->app->bind(PaidAccessCheck::class, MembershipPaidAccess::class);
        $this->app->bind(ContentModerationPolicy::class, UserFlagsModerationPolicy::class);
        $this->app->bind(PostSignatureResolver::class, UserSignatureResolver::class);

        $this->useZenCartModels();
        $this->remapGates();
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('zencart', 'ForumKit Zen Cart profile', 'zcwilt/forumkit-zencart', requires: ['core', 'entitlements', 'forums']),
        );

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'zencart',
            'forums',
            __DIR__.'/../database/extensions/forums.php',
            __DIR__.'/../database/migrations',
        ));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/forumkit-zencart.php' => config_path('forumkit-zencart.php'),
            ], 'forumkit-zencart-config');
        }
    }

    /**
     * Point the forum models at the subclasses that know the extra columns. `forumkit.models`
     * wins over the package's own map, and a host that set its own class is left alone.
     */
    private function useZenCartModels(): void
    {
        foreach (['forum' => Forum::class, 'thread' => Thread::class, 'post' => Post::class] as $key => $class) {
            if (config("forumkit.models.{$key}") === null) {
                config(["forumkit.models.{$key}" => $class]);
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
        foreach ((array) config('forumkit-zencart.gates') as $key => $map) {
            config([$key => $map]);
        }
    }
}
