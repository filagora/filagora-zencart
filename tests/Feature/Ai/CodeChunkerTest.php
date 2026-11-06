<?php

namespace Filagora\ZenCart\Tests\Feature\Ai;

use Filagora\ZenCart\Ai\Code\CodeChunker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodeChunkerTest extends TestCase
{
    private const CLASS_FILE = <<<'PHP'
<?php

namespace Zencart\Demo;

/**
 * Looks up a price.
 */
class PriceFinder
{
    public const DEFAULT_TAX = 0;

    private array $cache = [];

    /**
     * Find the price.
     */
    public function find(int $productId): float
    {
        $closure = function () { return 1; };

        return $this->cache[$productId] ?? 0.0;
    }

    abstract protected function missing(): void;

    public static function make(): static
    {
        return new static();
    }
}

interface Priced
{
    public function price(): float;
}
PHP;

    public function test_a_class_gives_one_chunk_per_method_with_its_docblock_and_class_name(): void
    {
        $chunks = collect((new CodeChunker)->chunk(self::CLASS_FILE, 'includes/classes/PriceFinder.php'));

        $symbols = $chunks->where('kind', 'method')->pluck('symbol')->all();
        $this->assertSame(['PriceFinder::find', 'PriceFinder::make'], $symbols);

        $find = $chunks->firstWhere('symbol', 'PriceFinder::find');
        $this->assertStringContainsString('Find the price.', $find['body']);
        $this->assertStringContainsString('return $this->cache', $find['body']);
        $this->assertSame(14, $find['start_line']);
        $this->assertSame(22, $find['end_line']);
    }

    public function test_the_closure_inside_a_method_is_not_a_chunk_of_its_own(): void
    {
        $chunks = (new CodeChunker)->chunk(self::CLASS_FILE, 'x.php');

        $this->assertNotContains('{closure}', array_column($chunks, 'symbol'));
        $this->assertCount(1, array_filter($chunks, fn (array $c): bool => str_contains($c['body'], 'function () { return 1; }')));
    }

    public function test_bodiless_methods_are_left_to_the_leftover_text_not_lost_or_chunked(): void
    {
        $chunks = (new CodeChunker)->chunk(self::CLASS_FILE, 'x.php');

        $this->assertNotContains('PriceFinder::missing', array_column($chunks, 'symbol'));
        $this->assertNotContains('Priced::price', array_column($chunks, 'symbol'));
    }

    public function test_class_properties_and_constants_become_a_file_chunk(): void
    {
        $chunks = collect((new CodeChunker)->chunk(self::CLASS_FILE, 'x.php'));

        $leftover = $chunks->where('kind', 'file')->pluck('body')->implode("\n");
        $this->assertStringContainsString('DEFAULT_TAX', $leftover);
        $this->assertStringContainsString('private array $cache', $leftover);
    }

    public function test_procedural_functions_wrapped_in_function_exists_are_found(): void
    {
        $source = <<<'PHP'
<?php
if (!function_exists('zen_get_thing')) {
  /**
   * Gets a thing.
   */
  function zen_get_thing($id)
  {
    global $db;
    return $db->Execute("select * from things where id = " . (int)$id);
  }
}
PHP;

        $chunks = (new CodeChunker)->chunk($source, 'includes/functions/general.php');

        $this->assertCount(1, $chunks);
        $this->assertSame('function', $chunks[0]['kind']);
        $this->assertSame('zen_get_thing', $chunks[0]['symbol']);
        $this->assertStringContainsString('Gets a thing.', $chunks[0]['body']);
        $this->assertSame(3, $chunks[0]['start_line']);
    }

    public function test_class_constants_named_class_and_anonymous_classes_do_not_break_scoping(): void
    {
        $source = <<<'PHP'
<?php
class A
{
    public function one() { return Foo::class; }

    public function two() { return new class { public function inner() {} }; }
}

function after_the_class() { return 1; }
PHP;

        $symbols = array_column((new CodeChunker)->chunk($source, 'a.php'), 'symbol');

        $this->assertContains('A::one', $symbols);
        $this->assertContains('A::two', $symbols);
        // Still recognised as a plain function: the class scope was closed.
        $this->assertContains('after_the_class', $symbols);
    }

    public function test_procedural_code_becomes_file_chunks_named_after_the_file(): void
    {
        $source = "<?php\n".implode("\n", array_map(fn (int $i): string => "\$config['option_{$i}'] = 'value number {$i}';", range(1, 40)));

        $chunks = (new CodeChunker)->chunk($source, 'includes/configure.php');

        $this->assertCount(1, $chunks);
        $this->assertSame('file', $chunks[0]['kind']);
        $this->assertSame('configure.php', $chunks[0]['symbol']);
    }

    public function test_a_long_function_is_split_into_overlapping_parts_within_the_size_cap(): void
    {
        $lines = array_map(fn (int $i): string => "    \$x{$i} = compute({$i}); // ".str_repeat('padding ', 5), range(1, 200));
        $source = "<?php\nfunction big_one()\n{\n".implode("\n", $lines)."\n}\n";

        $chunks = (new CodeChunker)->chunk($source, 'big.php');

        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame('big_one', $chunks[0]['symbol']);
        $this->assertSame('big_one (part 2)', $chunks[1]['symbol']);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(CodeChunker::MAX_CHUNK_CHARS + 120, mb_strlen($chunk['body']));
        }

        // The windows overlap, and together reach the last line.
        $this->assertLessThan($chunks[0]['end_line'], $chunks[1]['start_line']);
        $this->assertSame(204, end($chunks)['end_line']);
    }

    public function test_one_enormous_line_is_cut_not_skipped(): void
    {
        $source = "<?php\n\$data = '".str_repeat('x', 20_000)."';\n";

        $chunks = (new CodeChunker)->chunk($source, 'data.php');

        $this->assertNotEmpty($chunks);
        $this->assertLessThanOrEqual(CodeChunker::MAX_CHUNK_CHARS, mb_strlen($chunks[0]['body']));
    }

    #[DataProvider('brokenFiles')]
    public function test_broken_or_empty_files_do_not_throw(string $source): void
    {
        $chunks = (new CodeChunker)->chunk($source, 'broken.php');

        $this->assertIsArray($chunks);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenFiles(): array
    {
        return [
            'empty' => [''],
            'only the open tag' => ["<?php\n"],
            'unbalanced braces' => ["<?php\nfunction a() {\n  if (1) {\n    echo 1;\n"],
            'invalid utf-8' => ["<?php\nfunction a() { return \"\xff\xfe\"; }\n"],
            'html only' => ['<html><body>hello</body></html>'],
            'windows line endings' => ["<?php\r\nfunction a() {\r\n  return 1;\r\n}\r\n"],
        ];
    }

    public function test_unbalanced_function_falls_back_to_leftover_text(): void
    {
        $source = "<?php\n// a configuration file\n".str_repeat("\$a = 'a long enough line of text to count as something';\n", 5)."function broken() {\n  if (1) {\n";

        $chunks = (new CodeChunker)->chunk($source, 'broken.php');

        $this->assertSame(['file'], array_unique(array_column($chunks, 'kind')));
        $this->assertStringContainsString('function broken', implode("\n", array_column($chunks, 'body')));
    }
}
