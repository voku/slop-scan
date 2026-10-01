<?php

declare(strict_types=1);

namespace SlopScan\Review;

/**
 * Reads the narrow structure PHPStan writes into `phpstan-baseline.neon`:
 *
 *     parameters:
 *         ignoreErrors:
 *             -
 *                 message: '#^...$#'
 *                 count: 2
 *                 path: ../src/Foo.php
 *
 * It is deliberately not a NEON parser. Anything outside that shape is rejected instead of
 * being read as "no entries", so a comparison never silently treats unknown content as no growth.
 */
final class PhpStanBaseline
{
    /**
     * @return array<string,int> suppressed error count per project-relative source path
     */
    public static function countsByPath(string $contents, string $baselineDirectory): array
    {
        $counts = [];
        /** @var array{count:int,path:?string,seen:bool}|null $entry */
        $entry = null;
        $state = 'start';
        $lineNumber = 0;
        $blockEnd = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $rawLine) {
            ++$lineNumber;
            $line = trim($rawLine);
            if ($blockEnd !== null) {
                // Body of a multi-line `'''` / `"""` string (newer `rawMessage:` entries); not needed.
                $blockEnd = $line === $blockEnd ? null : $blockEnd;
                continue;
            }
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($state === 'start') {
                self::expect($line === 'parameters:', $lineNumber, 'expected "parameters:"');
                $state = 'parameters';
                continue;
            }

            if ($state === 'parameters') {
                if ($line === 'ignoreErrors: []') {
                    $state = 'done';
                    continue;
                }
                self::expect($line === 'ignoreErrors:', $lineNumber, 'expected "ignoreErrors:"');
                $state = 'entries';
                continue;
            }

            self::expect($state === 'entries', $lineNumber, 'unexpected content after the baseline entries');

            if (str_starts_with($line, '-')) {
                self::finish($entry, $counts, $baselineDirectory, $lineNumber);
                $entry = ['count' => 1, 'path' => null, 'seen' => false];
                $line = ltrim(substr($line, 1));
                if ($line === '') {
                    continue;
                }
            }

            if ($entry === null) {
                throw new \InvalidArgumentException(sprintf('Unsupported PHPStan baseline at line %d: entry content before the first "-".', $lineNumber));
            }
            $entry = self::applyKeyValue($entry, $line, $lineNumber);
            if (preg_match('/:\s*(\'\'\'|\"\"\")$/', $line, $block) === 1) {
                $blockEnd = $block[1];
            }
        }

        self::expect($blockEnd === null, $lineNumber, 'unterminated multi-line string');
        self::expect(in_array($state, ['entries', 'done'], true), $lineNumber, 'no "ignoreErrors" section found');
        self::finish($entry, $counts, $baselineDirectory, $lineNumber);
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @param array{count:int,path:?string,seen:bool} $entry
     * @return array{count:int,path:?string,seen:bool}
     */
    private static function applyKeyValue(array $entry, string $line, int $lineNumber): array
    {
        self::expect(preg_match('/^(?<key>[A-Za-z]+):\s*(?<value>.*)$/', $line, $match) === 1, $lineNumber, 'expected "key: value"');
        $value = self::unquote($match['value']);

        if ($match['key'] === 'count') {
            self::expect(ctype_digit($value) && (int) $value > 0, $lineNumber, '"count" must be a positive integer');
            $entry['count'] = (int) $value;
        } elseif ($match['key'] === 'path') {
            self::expect($value !== '', $lineNumber, '"path" must not be empty');
            $entry['path'] = $value;
        }

        $entry['seen'] = true;

        return $entry;
    }

    /**
     * @param array{count:int,path:?string,seen:bool}|null $entry
     * @param array<string,int> $counts
     */
    private static function finish(?array $entry, array &$counts, string $baselineDirectory, int $lineNumber): void
    {
        if ($entry === null) {
            return;
        }

        self::expect($entry['seen'] && $entry['path'] !== null, $lineNumber, 'an entry has no "path"');
        $path = self::normalizePath($entry['path'], $baselineDirectory);
        $counts[$path] = ($counts[$path] ?? 0) + $entry['count'];
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (preg_match("/^'(?<inner>.*)'$/s", $value, $match) === 1) {
            return str_replace("''", "'", $match['inner']);
        }
        if (preg_match('/^"(?<inner>.*)"$/s', $value, $match) === 1) {
            return stripcslashes($match['inner']);
        }

        return $value;
    }

    /**
     * Resolves an entry path against the baseline's directory. `%rootDir%` and
     * `%currentWorkingDirectory%` placeholders are treated as the project root, with any
     * `../` hops that only exist to climb out of vendor-style locations dropped.
     */
    private static function normalizePath(string $path, string $baselineDirectory): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^%(?:rootDir|currentWorkingDirectory)%/(.*)$~', $path, $match) === 1) {
            $path = ltrim((string) preg_replace('~^(?:\.\./)+~', '', $match[1]), '/');
            $baselineDirectory = '';
        }

        $segments = [];
        $combined = ($baselineDirectory === '' || str_starts_with($path, '/') ? '' : $baselineDirectory . '/') . $path;
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function expect(bool $condition, int $lineNumber, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException(sprintf('Unsupported PHPStan baseline at line %d: %s.', $lineNumber, $message));
        }
    }
}
