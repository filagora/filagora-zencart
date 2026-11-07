<?php

return [
    [
        'slug' => 'home',
        'custom_route' => '/',
        'title' => 'Home',
        'excerpt' => null,
        'seo_title' => 'Welcome',
        'seo_description' => 'Docs live at {docs}/start',
        'published_at' => '2026-08-13 17:02:28',
        'blocks' => [
            ['type' => 'raw_html', 'name' => 'Second', 'sort_order' => 20, 'data' => ['html' => '<img src="/storage/cms-media/box.png"><a href="{site}/go">go</a>']],
            ['type' => 'cta_band', 'name' => 'First', 'sort_order' => 10, 'data' => ['label' => 'Read', 'url' => '{docs}/user', 'links' => [['url' => '{docs}/dev'], ['url' => 'https://example.test/x']]]],
        ],
    ],
    [
        'slug' => 'privacy',
        'custom_route' => '/privacy',
        'title' => 'Privacy',
        'excerpt' => 'About privacy.',
        'seo_title' => null,
        'seo_description' => null,
        'published_at' => '2026-08-13 17:02:23',
        'blocks' => [
            ['type' => 'raw_html', 'name' => 'Policy', 'sort_order' => 10, 'data' => ['html' => '<p>Ask <!--email_off--><a href="mailto:{support_email}">us</a><!--/email_off-->.</p>']],
        ],
    ],
];
