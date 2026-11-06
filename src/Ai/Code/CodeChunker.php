<?php

declare(strict_types=1);

namespace Filagora\ZenCart\Ai\Code;

use PhpToken;

/**
 * Splits a PHP source file into pieces worth embedding: one chunk per named
 * function or method (with its docblock), and size-capped windows over
 * everything else - the procedural code between functions, a class's
 * properties and constants, a language file full of define()s.
 *
 * It tokenises rather than parses, so a file with a syntax error still yields
 * whatever it can, and the rest falls through to the windows.
 */
final class CodeChunker
{
    /** No chunk is longer than this, so one embedding request can hold many. */
    public const MAX_CHUNK_CHARS = 3_500;

    private const WINDOW_LINES = 60;

    private const WINDOW_OVERLAP_LINES = 8;

    /** Leftover code shorter than this (a bare `<?php`, a closing brace) is noise. */
    private const MIN_LEFTOVER_CHARS = 120;

    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_WHITESPACE];

    /**
     * @return list<array{kind: string, symbol: ?string, body: string, start_line: int, end_line: int}>
     */
    public function chunk(string $source, string $path): array
    {
        $source = str_replace("\r\n", "\n", mb_scrub($source));
        $lines = explode("\n", $source);
        $covered = [];
        $chunks = [];

        // Blade, CSS and JS have no PHP functions to cut at: just windows.
        if (! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
            return $this->wholeFile($source, $path);
        }

        foreach ($this->namedFunctions($source) as $function) {
            $body = implode("\n", array_slice($lines, $function['start'] - 1, $function['end'] - $function['start'] + 1));

            foreach ($this->windows($body, $function['start']) as $part => $window) {
                $chunks[] = [
                    'kind' => $function['kind'],
                    'symbol' => $function['symbol'].($part > 0 ? ' (part '.($part + 1).')' : ''),
                    'body' => $window['body'],
                    'start_line' => $window['start'],
                    'end_line' => $window['end'],
                ];
            }

            for ($line = $function['start']; $line <= $function['end']; $line++) {
                $covered[$line] = true;
            }
        }

        foreach ($this->leftoverRuns($lines, $covered) as [$start, $text]) {
            foreach ($this->windows($text, $start) as $part => $window) {
                $chunks[] = [
                    'kind' => 'file',
                    'symbol' => basename($path).($part > 0 ? ' (part '.($part + 1).')' : ''),
                    'body' => $window['body'],
                    'start_line' => $window['start'],
                    'end_line' => $window['end'],
                ];
            }
        }

        usort($chunks, fn (array $a, array $b): int => $a['start_line'] <=> $b['start_line']);

        return $chunks;
    }

    /**
     * @return list<array{kind: string, symbol: ?string, body: string, start_line: int, end_line: int}>
     */
    private function wholeFile(string $source, string $path): array
    {
        if (mb_strlen(trim($source)) < self::MIN_LEFTOVER_CHARS) {
            return [];
        }

        $chunks = [];

        foreach ($this->windows($source, 1) as $part => $window) {
            $chunks[] = [
                'kind' => 'file',
                'symbol' => $path.($part > 0 ? ' (part '.($part + 1).')' : ''),
                'body' => $window['body'],
                'start_line' => $window['start'],
                'end_line' => $window['end'],
            ];
        }

        return $chunks;
    }

    /**
     * Consecutive uncovered lines, as [first line number, text], without the
     * ones too small to be worth a vector.
     *
     * @param  list<string>  $lines
     * @param  array<int, true>  $covered
     * @return list<array{0: int, 1: string}>
     */
    private function leftoverRuns(array $lines, array $covered): array
    {
        $runs = [];
        $start = null;
        $buffer = [];

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (isset($covered[$number])) {
                if ($start !== null) {
                    $runs[] = [$start, implode("\n", $buffer)];
                    $start = null;
                    $buffer = [];
                }

                continue;
            }

            $start ??= $number;
            $buffer[] = $line;
        }

        if ($start !== null) {
            $runs[] = [$start, implode("\n", $buffer)];
        }

        return array_values(array_filter(
            $runs,
            fn (array $run): bool => mb_strlen(trim($run[1])) >= self::MIN_LEFTOVER_CHARS,
        ));
    }

    /**
     * Cuts text into overlapping windows no longer than the limits. Text that
     * already fits comes back as one.
     *
     * @return list<array{body: string, start: int, end: int}>
     */
    private function windows(string $text, int $firstLine): array
    {
        $lines = explode("\n", $text);

        if (mb_strlen($text) <= self::MAX_CHUNK_CHARS && count($lines) <= self::WINDOW_LINES) {
            return [['body' => $text, 'start' => $firstLine, 'end' => $firstLine + count($lines) - 1]];
        }

        $windows = [];
        $from = 0;
        $total = count($lines);

        while ($from < $total) {
            $to = $from;
            $length = 0;

            while ($to < $total && $to - $from < self::WINDOW_LINES) {
                // A single huge line (minified data, a long define) is cut, not skipped.
                $line = mb_substr($lines[$to], 0, self::MAX_CHUNK_CHARS);

                if ($to > $from && $length + mb_strlen($line) + 1 > self::MAX_CHUNK_CHARS) {
                    break;
                }

                $length += mb_strlen($line) + 1;
                $to++;
            }

            $body = implode("\n", array_map(fn (string $line): string => mb_substr($line, 0, self::MAX_CHUNK_CHARS), array_slice($lines, $from, $to - $from)));
            $windows[] = ['body' => $body, 'start' => $firstLine + $from, 'end' => $firstLine + $to - 1];

            if ($to >= $total) {
                break;
            }

            $from = max($from + 1, $to - self::WINDOW_OVERLAP_LINES);
        }

        return $windows;
    }

    /**
     * Named functions and methods that have a body, with the line they start
     * on (their docblock included) and the line their closing brace is on.
     *
     * @return list<array{symbol: string, kind: string, start: int, end: int}>
     */
    private function namedFunctions(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $found = [];
        /** @var list<array{0: string, 1: int}> $classes name and the brace depth inside it */
        $classes = [];
        $pendingClass = null;
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $before = $this->neighbour($tokens, $i, -1);

                // `Foo::class` and `new class {` are not declarations.
                if ($before !== null && $before->is([T_DOUBLE_COLON, T_NEW])) {
                    continue;
                }

                $name = $this->neighbour($tokens, $i, 1);
                $pendingClass = $name !== null && $name->is(T_STRING) ? $name->text : null;

                continue;
            }

            if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '{') {
                $depth++;

                if ($pendingClass !== null) {
                    $classes[] = [$pendingClass, $depth];
                    $pendingClass = null;
                }

                continue;
            }

            if ($token->text === '}') {
                if ($classes !== [] && end($classes)[1] === $depth) {
                    array_pop($classes);
                }
                $depth--;

                continue;
            }

            if (! $token->is(T_FUNCTION)) {
                continue;
            }

            $nameIndex = $this->indexOfNext($tokens, $i);
            if ($nameIndex !== null && $tokens[$nameIndex]->text === '&') {
                $nameIndex = $this->indexOfNext($tokens, $nameIndex);
            }

            // A closure has no name; fn() is a different token.
            if ($nameIndex === null || preg_match('/^[A-Za-z_]\w*$/', $tokens[$nameIndex]->text) !== 1) {
                continue;
            }

            if ($classes !== [] && end($classes)[1] !== $depth) {
                continue;
            }

            $end = $this->closingBrace($tokens, $nameIndex);
            if ($end === null) {
                continue;
            }

            $name = $tokens[$nameIndex]->text;
            $inClass = $classes !== [] ? end($classes)[0] : null;

            $found[] = [
                'symbol' => $inClass !== null ? "{$inClass}::{$name}" : $name,
                'kind' => $inClass !== null ? 'method' : 'function',
                'start' => $this->startLine($tokens, $i),
                'end' => $tokens[$end]->line,
            ];

            // The body is balanced, so skipping it leaves the depth as it was.
            $i = $end;
        }

        return $found;
    }

    /**
     * The index of the closing brace of the body that follows the parameter
     * list starting after $nameIndex, or null for a declaration with no body
     * (an interface or abstract method) or unbalanced braces.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function closingBrace(array $tokens, int $nameIndex): ?int
    {
        $count = count($tokens);
        $parens = 0;

        for ($j = $nameIndex + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;

            if ($text === '(') {
                $parens++;
            } elseif ($text === ')') {
                $parens--;
            } elseif ($parens === 0 && $text === ';') {
                return null;
            } elseif ($parens === 0 && $text === '{') {
                break;
            }
        }

        $braces = 0;

        for ($k = $j; $k < $count; $k++) {
            $token = $tokens[$k];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braces++;
            } elseif ($token->text === '}') {
                $braces--;

                if ($braces === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    /**
     * The first line of a function: its modifiers and the docblock above them.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function startLine(array $tokens, int $functionIndex): int
    {
        $start = $functionIndex;

        while ($start > 0 && $tokens[$start - 1]->is(self::MODIFIERS)) {
            $start--;
        }

        if ($start > 0 && $tokens[$start - 1]->is(T_DOC_COMMENT)) {
            $start--;
        }

        while ($tokens[$start]->is(T_WHITESPACE) && $start < $functionIndex) {
            $start++;
        }

        return $tokens[$start]->line;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function indexOfNext(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $count = count($tokens); $i < $count; $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  -1|1  $direction
     */
    private function neighbour(array $tokens, int $from, int $direction): ?PhpToken
    {
        for ($i = $from + $direction; isset($tokens[$i]); $i += $direction) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
