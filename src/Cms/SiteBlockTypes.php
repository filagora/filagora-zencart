<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Cms;

use Filagora\Cms\BlockTypes\BlockType;
use Filagora\Cms\BlockTypes\Fields\Field;

/**
 * The block types only zen-cart.com uses, with the fields of their forms, and the names the
 * site's older rows still carry. The stored JSON keys are the ones the live site already has.
 */
final class SiteBlockTypes
{
    /**
     * Old stored type => the type it became.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'download_hero' => 'promo_hero',
        'download_checklist' => 'checklist_callout',
        'download_technical' => 'split_info_panel',
        'download_community' => 'icon_callout',
    ];

    private const DONATION_PROVIDERS = ['paypal' => 'PayPal', 'zelle' => 'Zelle', 'github' => 'GitHub Sponsors'];

    private const SURFACES = ['panel' => 'Panel', 'soft' => 'Soft'];

    private const DEPLOY_IMAGES = [
        'cms-media/steps/step_1.jpg' => 'Step 1 image',
        'cms-media/steps/step_2.jpg' => 'Step 2 image',
        'cms-media/steps/step_3.jpg' => 'Step 3 image',
        'cms-media/steps/step_4.jpg' => 'Step 4 image',
    ];

    /**
     * @return list<BlockType>
     */
    public static function all(): array
    {
        $cta = static fn (string $prefix, string $name = 'Button'): array => [
            Field::text($prefix.'_label', $name.' label'),
            Field::url($prefix.'_url', $name.' URL'),
        ];

        return [
            new BlockType('promo_hero', 'Promo hero', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::strings('benefits', 'Benefits', itemLabel: 'Benefit'),
                ...$cta('cta'),
                Field::text('badge', 'Badge'),
                Field::text('meta_label', 'Meta label'),
                Field::text('meta_value', 'Meta value'),
                Field::text('meta_help_label', 'Meta help label'),
                Field::url('meta_help_url', 'Meta help URL'),
                Field::text('secondary_link_label', 'Secondary link label'),
                Field::url('secondary_link_url', 'Secondary link URL'),
            ]),

            new BlockType('donation_hero', 'Donation hero', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::markdown('note', 'Note'),
            ]),

            new BlockType('donation_method', 'Donation method', fields: [
                Field::select('provider', 'Provider', self::DONATION_PROVIDERS, 'paypal'),
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                ...$cta('cta'),
                Field::markdown('note', 'Note'),
                Field::text('tag', 'Zelle tag'),
                Field::text('donate_button_id', 'PayPal donate button ID'),
                Field::text('amounts_heading', 'Amounts heading', 'Leave blank to hide it.')
                    ->withFallback(fn (array $data): string => 'Recurring Support'),
                Field::text('card_heading', 'Card heading', 'Leave blank to hide it.')
                    ->withFallback(fn (array $data): string => match (trim((string) ($data['provider'] ?? 'paypal'))) {
                        'zelle' => 'Send To This Tag',
                        'github' => 'Sponsor Directly',
                        default => 'One-Time Donation',
                    }),
                Field::text('amount_suffix', 'Amount suffix', 'Leave blank to hide it.')
                    ->withFallback(fn (array $data): string => 'a month'),
                Field::text('tag_label', 'Tag label', 'Leave blank to hide it.')
                    ->withFallback(fn (array $data): string => 'Zelle Tag'),
                Field::text('item_name', 'PayPal payment description', 'PayPal requires one, so a blank one is replaced by the default.')
                    ->withFallback(fn (array $data): string => 'Buy the Zen Cart Team some coffee!', whenBlank: true),
                Field::strings('amounts', 'Suggested amounts', itemLabel: 'Amount'),
            ]),

            new BlockType('services_hero', 'Services hero', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::text('back_label', 'Back link label'),
                Field::url('back_url', 'Back link URL'),
            ]),

            new BlockType('provider_grid', 'Provider grid', fields: [
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::select('surface', 'Surface', self::SURFACES, 'panel'),
                Field::select('columns', 'Columns', ['2' => 'Two columns', '3' => 'Three columns'], '2')->asInteger(), // @phpstan-ignore argument.type (PHP turns the numeric keys into integers)
                Field::items('items', 'Providers', [
                    Field::text('name', 'Provider name'),
                    Field::url('url', 'URL'),
                    Field::url('image_url', 'Logo URL'),
                    Field::textarea('logo_html', 'Logo HTML', rows: 3),
                    Field::text('badge', 'Badge'),
                    Field::text('cta_label', 'Button label'),
                    Field::toggle('dark_logo_background', 'Dark logo background'),
                    Field::strings('tags', 'Tags', itemLabel: 'Tag'),
                    Field::markdown('body', 'Body', rows: 4),
                ], titleKey: 'name'),
            ]),

            new BlockType('about_hero', 'About hero', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
            ]),

            new BlockType('home_hero', 'Home hero', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('stats', 'Stats', [
                    Field::text('label', 'Label'),
                    Field::text('value', 'Value'),
                ], titleKey: 'label'),
                ...$cta('primary', 'Primary button'),
                ...$cta('secondary', 'Secondary button'),
            ]),

            new BlockType('home_value_grid', 'Home value grid', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::items('items', 'Items', [
                    Field::text('icon_class', 'Icon class'),
                    Field::text('title', 'Title'),
                    Field::markdown('body', 'Body'),
                    Field::url('url', 'Link URL'),
                    Field::toggle('highlight', 'Highlight item'),
                ], titleKey: 'title'),
            ]),

            new BlockType('home_included_grid', 'Home included grid', fields: [
                Field::text('title', 'Title'),
                Field::items('left_items', 'Left column', [
                    Field::text('title', 'Title'),
                    Field::markdown('body', 'Body'),
                ], titleKey: 'title'),
                Field::items('right_items', 'Right column', [
                    Field::text('title', 'Title'),
                    Field::markdown('body', 'Body'),
                ], titleKey: 'title'),
            ]),

            new BlockType('home_deploy_steps', 'Home deployment steps', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::items('steps', 'Deployment steps', [
                    Field::text('title', 'Step title'),
                    Field::markdown('body', 'Body', rows: 2),
                    Field::select('image_key', 'Step image', self::DEPLOY_IMAGES, array_key_first(self::DEPLOY_IMAGES)),
                    Field::text('image_position', 'Image position', 'A CSS object-position value, for example center 35%.'),
                    Field::toggle('open', 'Open by default'),
                ], titleKey: 'title'),
            ]),

            new BlockType('home_feature_columns', 'Home feature columns', fields: [
                Field::select('surface', 'Surface', ['panel' => 'Panel', 'panel_strong' => 'Panel strong'], 'panel'),
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::items('left_items', 'Left column', [
                    Field::text('label', 'Lead label'),
                    Field::markdown('body', 'Body', rows: 2),
                ], titleKey: 'label'),
                Field::items('right_items', 'Right column', [
                    Field::text('label', 'Lead label'),
                    Field::markdown('body', 'Body', rows: 2),
                ], titleKey: 'label'),
                ...$cta('cta'),
                Field::markdown('footer_note', 'Footer note'),
            ]),

            new BlockType('home_compare_section', 'Home compare section', fields: [
                Field::text('eyebrow', 'Eyebrow'),
                Field::text('title', 'Title'),
                Field::markdown('body', 'Body'),
                Field::text('ownership_title', 'Ownership title'),
                Field::items('ownership_paragraphs', 'Ownership paragraphs', [
                    Field::markdown('text', 'Text'),
                    Field::text('emphasized_text', 'Emphasised text'),
                ], legacyScalarKey: 'text'),
                ...$cta('ownership_cta', 'Ownership button'),
                Field::text('cost_title', 'Cost title'),
                Field::items('cost_rows', 'Cost rows', [
                    Field::text('icon_class', 'Icon class'),
                    Field::text('label', 'Label'),
                    Field::text('value', 'Value'),
                ], titleKey: 'label'),
                Field::text('requirements_title', 'Requirements title'),
                Field::items('requirements_rows', 'Requirements rows', [
                    Field::text('icon_class', 'Icon class'),
                    Field::text('label', 'Label'),
                    Field::text('value', 'Value'),
                ], titleKey: 'label'),
                Field::text('requirements_body_title', 'Requirements body title'),
                Field::strings('requirements_body_paragraphs', 'Requirements paragraphs', markdown: true, itemLabel: 'Paragraph'),
                ...$cta('requirements_cta', 'Requirements button'),
            ]),
        ];
    }
}
