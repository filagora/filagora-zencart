<?php

declare(strict_types=1);

use Filagora\Cms\BlockTypes\BlockTypeRegistry;
use Filagora\Cms\Navigation\NavigationLinkTypes;
use Filagora\Core\Settings\SettingDefinition;
use Filagora\Core\Settings\SettingsRegistry;
use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\ZenCart\Cms\SiteBlockTypes;
use Filagora\ZenCart\Tests\Support\TestUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

const ZC_SITE_TYPES = [
    'promo_hero', 'donation_hero', 'donation_method', 'services_hero', 'provider_grid', 'about_hero',
    'home_hero', 'home_value_grid', 'home_included_grid', 'home_deploy_steps', 'home_feature_columns', 'home_compare_section',
];

function blockTypes(): BlockTypeRegistry
{
    return app(BlockTypeRegistry::class);
}

it('registers the twelve site block types, each with a form', function () {
    foreach (ZC_SITE_TYPES as $key) {
        expect(blockTypes()->get($key)?->hasForm())->toBeTrue($key);
    }

    expect(blockTypes()->has('hero'))->toBeTrue();
});

it('round-trips every site type: nothing stored is lost and a second pass changes nothing', function (string $key) {
    $type = blockTypes()->get($key);
    $once = $type->normalize($type->hydrate([]));

    expect($type->normalize($type->hydrate($once)))->toBe($once)
        ->and(array_keys($once))->toBe(array_values(array_filter(array_map(fn ($f) => $f->key ?? null, $type->fields))));
})->with(ZC_SITE_TYPES);

it('keeps the keys the live site stores', function () {
    expect(array_keys(blockTypes()->get('donation_method')->normalize([])))->toBe([
        'provider', 'eyebrow', 'title', 'body', 'cta_label', 'cta_url', 'note', 'tag', 'donate_button_id',
        'amounts_heading', 'card_heading', 'amount_suffix', 'tag_label', 'item_name', 'amounts',
    ])->and(array_keys(blockTypes()->get('promo_hero')->normalize([])))->toBe([
        'title', 'body', 'benefits', 'cta_label', 'cta_url', 'badge', 'meta_label', 'meta_value',
        'meta_help_label', 'meta_help_url', 'secondary_link_label', 'secondary_link_url',
    ]);
});

it('loads the old download_* rows as the types they became', function () {
    foreach (SiteBlockTypes::ALIASES as $legacy => $canonical) {
        expect(blockTypes()->normalize($legacy))->toBe($canonical)
            ->and(blockTypes()->get($canonical))->not->toBeNull();
    }

    expect(blockTypes()->normalize(' Download_Hero '))->toBe('promo_hero');
});

it('gives a donation block saved before its wording fields existed the original wording', function () {
    $type = blockTypes()->get('donation_method');

    expect($type->hydrate(['provider' => 'zelle']))->toMatchArray([
        'amounts_heading' => 'Recurring Support', 'card_heading' => 'Send To This Tag', 'amount_suffix' => 'a month',
        'tag_label' => 'Zelle Tag', 'item_name' => 'Buy the Zen Cart Team some coffee!',
    ])->and($type->hydrate(['provider' => 'github'])['card_heading'])->toBe('Sponsor Directly')
        ->and($type->hydrate([])['card_heading'])->toBe('One-Time Donation');
});

it('keeps wording an editor blanked to hide it, except the PayPal description', function () {
    $saved = blockTypes()->get('donation_method')->normalize(['provider' => 'paypal', 'amounts_heading' => '', 'card_heading' => ' ', 'item_name' => '']);

    expect($saved['amounts_heading'])->toBe('')
        ->and($saved['card_heading'])->toBe('')
        ->and($saved['item_name'])->toBe('Buy the Zen Cart Team some coffee!');
});

it('saves the provider grid columns as a number, and the donation amounts as plain strings', function () {
    $grid = blockTypes()->get('provider_grid')->normalize(['columns' => '3', 'items' => [['name' => 'Host', 'tags' => [['text' => ' fast '], '']]]]);
    $donation = blockTypes()->get('donation_method')->normalize(['amounts' => [['text' => '$5'], '$10', ['text' => ' ']]]);

    expect($grid['columns'])->toBe(3)
        ->and(blockTypes()->get('provider_grid')->normalize([])['columns'])->toBe(2)
        ->and($grid['items'][0]['tags'])->toBe(['fast'])
        ->and($donation['amounts'])->toBe(['$5', '$10']);
});

it('upgrades a plain-string ownership paragraph into the text and emphasis pair', function () {
    $form = blockTypes()->get('home_compare_section')->hydrate(['ownership_paragraphs' => ['You own it.']]);

    expect($form['ownership_paragraphs'][0])->toMatchArray(['text' => 'You own it.', 'emphasized_text' => '']);
});

it('maps the cms abilities onto operator keys, and a grant of the key opens the gate', function () {
    expect(config('filagora-cms.gates'))->toBe([
        'filagora.cms.manage' => 'operator.cms.manage',
        'filagora.cms.preview' => 'operator.cms.preview',
    ]);

    $editor = TestUser::make('Editor');
    expect(Gate::forUser($editor)->allows('filagora.cms.manage'))->toBeFalse();

    UserEntitlementGrant::create(['user_id' => $editor->id, 'entitlement_key' => 'operator.cms.manage', 'grant_type' => 'manual']);
    expect(Gate::forUser($editor)->allows('filagora.cms.manage'))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('filagora.cms.preview'))->toBeFalse();
});

it('registers the three link kinds with their allowed references', function () {
    $types = app(NavigationLinkTypes::class);

    expect(array_keys($types->options()))->toBe(['url', 'legal_page', 'app_page', 'action'])
        ->and(array_keys($types->references('legal_page')))->toBe(['privacy', 'cookie_policy'])
        ->and(array_keys($types->references('app_page')))->toBe(['profile', 'my_profile', 'billing', 'messages', 'members', 'admin_dashboard', 'ai_chat'])
        ->and($types->keepsPlaceholder('action'))->toBeTrue()
        ->and($types->keepsPlaceholder('app_page'))->toBeFalse();
});

it('resolves legal pages from their settings, falling back to the defaults', function () {
    $types = app(NavigationLinkTypes::class);

    expect($types->resolve('legal_page', 'privacy'))->toBe('/privacy')
        ->and($types->resolve('legal_page', 'cookie_policy'))->toBe('/cookie-policy')
        ->and($types->resolve('legal_page', 'terms'))->toBeNull();

    app(SettingsRegistry::class)->set('privacy_page', 'privacy-notice');
    expect($types->resolve('legal_page', 'privacy'))->toBe('/pages/privacy-notice');

    app(SettingsRegistry::class)->set('privacy_page', '/legal/privacy');
    expect($types->resolve('legal_page', 'privacy'))->toBe('/legal/privacy');
})->skip(fn () => ! class_exists(SettingDefinition::class));

it('resolves application pages only when allowed and routed, and the logout action only as itself', function () {
    Route::get('/profile', fn () => '')->name('profile.show');
    Route::get('/members/{user}', fn () => '')->name('members.show');
    app('router')->getRoutes()->refreshNameLookups();
    $types = app(NavigationLinkTypes::class);

    expect($types->resolve('app_page', 'profile'))->toEndWith('/profile')
        ->and($types->resolve('app_page', 'admin_dashboard'))->toEndWith('/dashboard')
        ->and($types->resolve('app_page', 'billing'))->toBeNull()
        ->and($types->resolve('app_page', 'not-listed'))->toBeNull()
        ->and($types->resolve('app_page', 'my_profile'))->toBe('#')
        ->and($types->resolve('action', 'logout'))->toBe('#')
        ->and($types->resolve('action', 'delete_everything'))->toBeNull();

    $this->actingAs(TestUser::make('Member'));
    expect($types->resolve('app_page', 'my_profile'))->toContain('/members/');
});
