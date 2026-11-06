<?php

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Equals;
use Filagora\ZenCart\Ai\Code\ZenCartVersionDetector;
use Filagora\ZenCart\Ai\Code\ZenCartVersions;
use Filagora\ZenCart\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ZenCartVersionDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filagora-zencart.code.tags' => [
            'v1.5.7', 'v1.5.7c', 'v1.5.8', 'v1.5.8a',
            'v2.0.1', 'v2.2.2',
            'v3.0-dev' => 'master',
        ]]);
    }

    /**
     * @return array<string, array{string, ?string, ?string}> text, version, generation (both null when nothing is named)
     */
    public static function texts(): array
    {
        return [
            'exact with letter' => ['I run Zen Cart 1.5.8a and get a blank page', 'v1.5.8a', '1.5.x'],
            'v prefix' => ['upgraded to v2.0.1 yesterday', 'v2.0.1', '2.x'],
            'bare 1.5 number is distinctive' => ['my 1.5.7c store', 'v1.5.7c', '1.5.x'],
            'compact zc' => ['site is ZC158 with php 7.4', 'v1.5.8', '1.5.x'],
            'compact zc with letter' => ['zc157c', 'v1.5.7c', '1.5.x'],
            'version word' => ['Zen Cart version 2.2.2 here', 'v2.2.2', '2.x'],
            'a version we do not hold falls back to its line' => ['Zen Cart 1.5.9 beta', null, '1.5.x'],
            'two part version is only a line' => ['using zencart 2.0', null, '2.x'],
            'the dev branch label' => ['testing Zen Cart v3.0-dev', null, '3.x'],
            'a later number wins over an unrelated earlier one' => ['mysql version 5.7.3, Zen Cart 2.0.1', 'v2.0.1', '2.x'],
            'php version alone is not zen cart' => ['PHP version 8.2.1 and mysql 5.7', null, null],
            'bare jquery style number is not zen cart' => ['jquery 3.5.1 is loaded', null, null],
            'a price is not a version' => ['it costs 2.50 and weighs 1.2', null, null],
            'words that merely start with v' => ['a very nice 2.0 theme, over 1.2 metres', null, null],
            'nothing' => ['how do I install a plugin?', null, null],
            'empty' => ['', null, null],
        ];
    }

    #[DataProvider('texts')]
    public function test_it_finds_the_version_a_text_is_about(string $text, ?string $version, ?string $generation): void
    {
        $selection = app(ZenCartVersionDetector::class)->detect($text);

        $this->assertSame($generation, $selection?->generation, $text);
        $this->assertSame($version, $selection?->version, $text);
        $this->assertFalse($selection?->assumed ?? false);
    }

    public function test_the_first_text_that_names_a_version_wins(): void
    {
        $detector = app(ZenCartVersionDetector::class);

        $this->assertSame('v2.2.2', $detector->detect('Zen Cart 2.2.2 now', 'earlier: Zen Cart 1.5.7')?->version);
        $this->assertSame('v1.5.7', $detector->detect('no version here', 'earlier: Zen Cart 1.5.7')?->version);
        $this->assertNull($detector->detect('nothing', 'nothing either'));
    }

    public function test_with_nothing_named_the_latest_release_is_assumed_and_says_so_never_the_dev_branch(): void
    {
        $assumed = app(ZenCartVersionDetector::class)->assumed();

        $this->assertSame('v2.2.2', $assumed->version);
        $this->assertSame('2.x', $assumed->generation);
        $this->assertTrue($assumed->assumed);
        $this->assertEquals(new All(new Equals('repo', 'zencart'), new Equals('versions', 'v2.2.2')), $assumed->filter());
    }

    public function test_the_latest_release_is_the_newest_tag_not_the_last_listed(): void
    {
        config(['filagora-zencart.code.tags' => ['v2.2.2', 'v1.5.8a', 'v2.0.1', 'v3.0-dev' => 'master']]);

        $this->assertSame('v2.2.2', app(ZenCartVersions::class)->latestReleased());
    }

    public function test_with_only_a_branch_configured_the_newest_line_is_assumed(): void
    {
        config(['filagora-zencart.code.tags' => ['v3.0-dev' => 'master']]);

        $assumed = app(ZenCartVersionDetector::class)->assumed();

        $this->assertNull($assumed->version);
        $this->assertSame('3.x', $assumed->generation);
        $this->assertTrue($assumed->assumed);
    }

    public function test_a_selection_becomes_a_filter_on_the_version_or_the_line(): void
    {
        $detector = app(ZenCartVersionDetector::class);

        $this->assertEquals(new All(new Equals('repo', 'zencart'), new Equals('versions', 'v1.5.8a')), $detector->detect('Zen Cart 1.5.8a')->filter());
        $this->assertEquals(new All(new Equals('repo', 'zencart'), new Equals('generations', '1.5.x')), $detector->detect('Zen Cart 1.5.9')->filter());
    }

    public function test_links_point_at_the_tag_or_the_branch_a_version_is_read_from(): void
    {
        $versions = app(ZenCartVersions::class);

        $this->assertSame(
            'https://github.com/zencart/zencart/blob/v2.2.2/includes/functions/functions_general.php#L10-L20',
            $versions->blobUrl('v2.2.2', 'includes/functions/functions_general.php', 10, 20),
        );
        $this->assertSame('https://github.com/zencart/zencart/blob/master/index.php#L5', $versions->blobUrl('v3.0-dev', 'index.php', 5, 5));
        $this->assertSame('https://github.com/zencart/zencart/blob/v2.2.2/a%20b.php#L1-L2', $versions->blobUrl('v2.2.2', 'a b.php', 1, 2));
    }

    public function test_with_no_versions_configured_nothing_is_assumed(): void
    {
        config(['filagora-zencart.code.tags' => []]);

        $this->assertNull(app(ZenCartVersionDetector::class)->assumed());
        $this->assertNull(app(ZenCartVersionDetector::class)->detect('Zen Cart 2.0.1'));
    }
}
