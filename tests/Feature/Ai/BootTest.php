<?php

declare(strict_types=1);

use Filagora\ZenCart\Ai\Code\ZenCartVersionDetector;
use Filagora\ZenCart\Ai\Code\ZenCartVersions;
use Filagora\ZenCart\Ai\Retrieval\CodeRetrievalSource;
use Filagora\ZenCart\Ai\Retrieval\DocsRetrievalSource;
use Filagora\ZenCart\Ai\Retrieval\PluginsRetrievalSource;

it('registers the chat sources without building what they need while the application boots', function () {
    // They are registered during boot, which is too early to build anything that reads settings: a
    // source in another package once built the forum access rules there and broke a host's staff preview.
    expect(app()->resolved(ZenCartVersionDetector::class))->toBeFalse()
        ->and(app()->resolved(ZenCartVersions::class))->toBeFalse();
});

it('still builds them, with their dependencies, when they are asked', function () {
    foreach ([CodeRetrievalSource::class, DocsRetrievalSource::class, PluginsRetrievalSource::class] as $source) {
        expect(app($source))->toBeInstanceOf($source);
    }
});
