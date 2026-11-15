<?php

declare(strict_types=1);

use Filagora\Cms\Models\CmsMediaAsset;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Support\Tables;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Newsletters\Contracts\NewsletterMedia;
use Filagora\ZenCart\Support\CmsNewsletterMedia;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('maps the newsletter ability to the site\'s newsletter entitlement', function () {
    $staff = TestUser::make('Staff');
    $other = TestUser::make('Other');
    UserEntitlementGrant::create(['user_id' => $staff->id, 'entitlement_key' => 'operator.newsletter.manage', 'grant_type' => 'manual']);

    expect(config('filagora-newsletters.gates'))->toBe(['filagora.newsletters.manage' => 'operator.newsletter.manage'])
        ->and(Gate::forUser($staff)->allows('filagora.newsletters.manage'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('filagora.newsletters.manage'))->toBeFalse();
});

it('declares newsletters as a module the profile builds on', function () {
    expect(app(ModuleRegistry::class)->all())->toHaveKey('newsletters');
});

it('leaves the newsletter tables to the package and keeps the webhook replay guard', function () {
    $site = array_keys(app(SchemaRegistry::class)->get('zencart-site')->tables);
    $package = array_keys(app(SchemaRegistry::class)->get('newsletters')->tables);

    expect($site)->toContain('webhook_events')
        ->and(array_intersect($site, $package))->toBe([])
        ->and(Schema::hasTable(Tables::name('newsletter_subscribers')))->toBeTrue()
        ->and(Schema::hasTable(Tables::name('webhook_events')))->toBeTrue()
        ->and(DB::table(Tables::name('newsletter_blocks'))->pluck('key')->all())->toEqualCanonicalizing(['shared_header', 'shared_footer']);
});

it('lets a site that ran the old profile migration adopt the package tables as they are', function () {
    $schema = app(SchemaRegistry::class)->get('newsletters');
    DB::table('migrations')->whereIn('migration', $schema->migrationNames())->delete();

    expect(app(Adopter::class)->planFor($schema)->status)->toBe(AdoptionStatus::Adoptable);
});

it('serves newsletter images from the CMS media library', function () {
    expect(app(NewsletterMedia::class))->toBeInstanceOf(CmsNewsletterMedia::class);

    Storage::fake('public');
    Storage::disk('public')->put('cms-media/logo.png', 'logo');
    Storage::disk('public')->put('cms-media/plain.png', 'plain');

    $logo = CmsMediaAsset::query()->create(['disk' => 'public', 'path' => 'cms-media/logo.png', 'filename' => 'logo.png', 'mime_type' => 'image/png', 'size' => 4, 'alt_text' => 'Zen Cart Logo']);
    $plain = CmsMediaAsset::query()->create(['disk' => 'public', 'path' => 'cms-media/plain.png', 'filename' => 'plain.png', 'mime_type' => 'image/png', 'size' => 4]);
    $doc = CmsMediaAsset::query()->create(['disk' => 'public', 'path' => 'cms-media/a.pdf', 'filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 4]);

    $media = app(NewsletterMedia::class);

    expect($media->find($logo->id)['alt'])->toBe('Zen Cart Logo')
        ->and($media->find($logo->id)['url'])->toContain('logo.png')
        ->and($media->find($plain->id)['alt'])->toBe('plain.png')
        ->and($media->find($doc->id))->toBeNull()
        ->and($media->find(999999))->toBeNull()
        ->and($media->imageOptions())->toBe([$logo->id => 'logo.png - Zen Cart Logo', $plain->id => 'plain.png']);
});
