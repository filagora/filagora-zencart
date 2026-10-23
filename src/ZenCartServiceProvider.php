<?php

declare(strict_types=1);

namespace Filagora\ZenCart;

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Compliance\Contracts\UserAnonymizer;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Entitlements\Contracts\SuperUserCheck;
use Filagora\Forums\Contracts\ContentModerationPolicy;
use Filagora\Forums\Contracts\PaidAccessCheck;
use Filagora\Forums\Contracts\PostSignatureResolver;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Notifications\Category;
use Filagora\Notifications\Contracts\EmailSuppression;
use Filagora\Notifications\Enums\NotificationChannel;
use Filagora\Notifications\Events\PreferenceChanged;
use Filagora\Notifications\NotificationCategories;
use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\ZenCart\Cms\MembershipRule;
use Filagora\ZenCart\Cms\NotVisitedDaysRule;
use Filagora\ZenCart\Cms\SiteBlockTypes;
use Filagora\ZenCart\Cms\SiteLinkTypes;
use Filagora\ZenCart\Console\StampManifestIdsCommand;
use Filagora\ZenCart\Models\Forum;
use Filagora\ZenCart\Models\Plugin;
use Filagora\ZenCart\Models\PluginRelease;
use Filagora\ZenCart\Models\Post;
use Filagora\ZenCart\Models\PostAttachment;
use Filagora\ZenCart\Models\Reaction;
use Filagora\ZenCart\Models\Thread;
use Filagora\ZenCart\Plugins\LegacyPluginIdentifiers;
use Filagora\ZenCart\Plugins\StampManifestPluginId;
use Filagora\ZenCart\Plugins\ZcVersionCompatibility;
use Filagora\ZenCart\Plugins\ZenCartApiFields;
use Filagora\ZenCart\Plugins\ZenCartReleaseExtension;
use Filagora\ZenCart\Support\EntitlementEmailSuppression;
use Filagora\ZenCart\Support\MembershipPaidAccess;
use Filagora\ZenCart\Support\RoleSuperUserCheck;
use Filagora\ZenCart\Support\SyncsLegacyNotificationColumns;
use Filagora\ZenCart\Support\UserFlagsModerationPolicy;
use Filagora\ZenCart\Support\UserSignatureResolver;
use Filagora\ZenCart\Support\ZenCartUserAnonymizer;
use Illuminate\Support\Facades\Event;
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
        $this->app->bind(UserAnonymizer::class, ZenCartUserAnonymizer::class);
        $this->app->bind(EmailSuppression::class, EntitlementEmailSuppression::class);
        $this->app->bind(Compatibility::class, ZcVersionCompatibility::class);
        $this->app->bind(ApiFields::class, ZenCartApiFields::class);
        $this->app->bind(PluginIdentifiers::class, LegacyPluginIdentifiers::class);

        $this->useZenCartModels();
        $this->remapGates();
        $this->extendCms();
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('zencart', 'Filagora Zen Cart profile', 'filagora/filagora-zencart', requires: ['core', 'entitlements', 'forums', 'cms', 'compliance', 'messages', 'notifications', 'plugins']),
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

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'zencart',
            'messages',
            __DIR__.'/../database/extensions/messages.php',
            __DIR__.'/../database/migrations/messages',
        ));

        $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
            'zencart',
            'plugins',
            __DIR__.'/../database/extensions/plugins.php',
            __DIR__.'/../database/migrations/plugins',
        ));

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('zencart', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations/zc-versions'),
        );

        $this->joinNotifications();
        $this->joinPlugins();

        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/cms', __DIR__.'/../database/migrations/messages', __DIR__.'/../database/migrations/plugins', __DIR__.'/../database/migrations/zc-versions']);

        if ($this->app->runningInConsole()) {
            $this->commands([StampManifestIdsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-zencart.php' => config_path('filagora-zencart.php'),
            ], 'filagora-zencart-config');
        }
    }

    /**
     * The upload checks and fields of a Zen Cart plugin, and the manifest id written into an
     * archive when its release goes live.
     */
    private function joinPlugins(): void
    {
        $this->callAfterResolving(ReleaseExtensions::class, fn (ReleaseExtensions $extensions) => $extensions->register($this->app->make(ZenCartReleaseExtension::class)));

        Event::listen(ReleasePublished::class, [StampManifestPluginId::class, 'handle']);
    }

    /**
     * The categories only this site has, added after the packages' own so they list after them,
     * and the copy of two choices into the user columns other parts of the site still read.
     */
    private function joinNotifications(): void
    {
        $this->app->booted(function (): void {
            $categories = $this->app->make(NotificationCategories::class);
            $everywhere = [NotificationChannel::EMAIL, NotificationChannel::PUSH, NotificationChannel::IN_APP];

            $categories->register(new Category('plugins.release_updates', 'Updates to plugins you follow', $everywhere, unsubscribable: true));
            $categories->register(new Category('privacy.request_updates', 'Updates to your privacy requests'));
        });

        Event::listen(PreferenceChanged::class, [SyncsLegacyNotificationColumns::class, 'handle']);
    }

    /**
     * The site's own block types and their old names, its menu link kinds and its announcement
     * targeting rules, added to the packages' registries when they are first built.
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

        // The memberships package adds its own `membership` rule unless one is already there, so
        // registering this one whenever the registry is first used wins in either order.
        $this->callAfterResolving(TargetingRules::class, function (TargetingRules $rules): void {
            $rules->register($this->app->make(MembershipRule::class));
            $rules->register($this->app->make(NotVisitedDaysRule::class));
        });
    }

    /**
     * Point the forum models at the subclasses that know the extra columns. `filagora.models`
     * wins over the package's own map, and a host that set its own class is left alone.
     */
    private function useZenCartModels(): void
    {
        foreach (['forum' => Forum::class, 'thread' => Thread::class, 'post' => Post::class, 'reaction' => Reaction::class, 'post_attachment' => PostAttachment::class, 'plugin' => Plugin::class, 'release' => PluginRelease::class] as $key => $class) {
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
