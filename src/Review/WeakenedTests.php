<?php

declare(strict_types=1);

namespace SlopScan\Review;

use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Discoverer;
use SlopScan\Model\Finding;

final class WeakenedTests
{
    private const RULE_ID = 'php.weakened-tests';
    private const WEAK_SCORE = 1.25;
    private const MEDIUM_SCORE = 2.0;

    /**
     * @param list<string> $ignore
     * @return list<Finding>
     */
    public static function comparePaths(
        string $basePath,
        string $headPath,
        array $ignore = [],
        ?string $baseConfigFile = null,
        ?string $headConfigFile = null,
    ): array {
        $beforeByPath = self::inventories($basePath, $ignore, $baseConfigFile);
        $afterByPath = self::inventories($headPath, $ignore, $headConfigFile);
        $findings = [];
        $relocations = self::addedTests($beforeByPath, $afterByPath);

        $paths = array_values(array_unique(array_merge(array_keys($beforeByPath), array_keys($afterByPath))));
        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            if ((array_key_exists($path, $beforeByPath) && $beforeByPath[$path] === null)
                || (array_key_exists($path, $afterByPath) && $afterByPath[$path] === null)
            ) {
                continue;
            }

            $before = $beforeByPath[$path] ?? [];
            $after = $afterByPath[$path] ?? [];

            foreach (self::compareInventories($path, $before, $after, $relocations) as $finding) {
                $findings[] = $finding;
            }
        }

        usort(
            $findings,
            static fn (Finding $left, Finding $right): int => strcmp(
                $left->deltaIdentity['occurrences'][0]['fingerprint'] ?? '',
                $right->deltaIdentity['occurrences'][0]['fingerprint'] ?? '',
            ),
        );

        return $findings;
    }

    /**
     * @param array<string,TestBody> $before
     * @param array<string,TestBody> $after
     * @param array<string,TestBody> $relocations Tests added in any file, keyed by "path\0name"; matched entries are consumed
     * @return list<Finding>
     */
    public static function compareInventories(string $path, array $before, array $after, array &$relocations = []): array
    {
        $findings = [];

        foreach ($after as $name => $test) {
            $old = $before[$name] ?? null;

            if ($old instanceof TestBody && $test->skipped && !$old->skipped) {
                $findings[] = self::finding(
                    $path,
                    $test,
                    'skipped',
                    'medium',
                    'high',
                    self::label($name) . ' is now skipped',
                    ['before=active', 'after=skipped'],
                    'Restore an active assertion path, or document why this test cannot run in the relevant environment.',
                );
                continue;
            }

            if ($test->trivial > ($old->trivial ?? 0)) {
                $existing = $old instanceof TestBody;
                $findings[] = self::finding(
                    $path,
                    $test,
                    'trivial',
                    $existing ? 'medium' : 'weak',
                    $existing ? 'high' : 'medium',
                    $existing
                        ? self::label($name) . ' gained an assertion that cannot fail'
                        : self::label($name) . ' contains an assertion that cannot fail',
                    ['trivial-before=' . ($old->trivial ?? 0), 'trivial-after=' . $test->trivial],
                    'Assert an observable result instead of a condition that is true by construction.',
                );
                continue;
            }

            if ($old instanceof TestBody && !$test->skipped && $test->assertions < $old->assertions) {
                $findings[] = self::finding(
                    $path,
                    $test,
                    'assertions',
                    'medium',
                    'medium',
                    sprintf(
                        '%s makes %d assertion%s, down from %d',
                        self::label($name),
                        $test->assertions,
                        $test->assertions === 1 ? '' : 's',
                        $old->assertions,
                    ),
                    ['assertions-before=' . $old->assertions, 'assertions-after=' . $test->assertions],
                    'Verify that the removed checks are intentionally covered elsewhere; otherwise restore equivalent assertions.',
                );
            }
        }

        foreach (self::deletedFindings($path, $before, $after, $relocations) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @param list<string> $ignore
     * @return array<string,null|array<string,TestBody>>
     */
    private static function inventories(string $root, array $ignore, ?string $configFile): array
    {
        $config = Config::load($root, $configFile);
        $config['ignores'] = array_values(array_merge($config['ignores'] ?? [], $ignore));
        $discovery = Discoverer::discover($root, $config, DefaultRegistry::create());
        $inventories = [];

        foreach ($discovery['files'] as $file) {
            if ($file->languageId !== 'php' || !TestInventory::isTestPath($file->path)) {
                continue;
            }

            $text = file_get_contents($file->absolutePath);
            if ($text === false) {
                continue;
            }

            $inventories[$file->path] = TestInventory::fromSource($text, $file->path);
        }

        ksort($inventories, SORT_STRING);

        return $inventories;
    }

    /**
     * @param array<string,TestBody> $before
     * @param array<string,TestBody> $after
     * @param array<string,TestBody> $relocations
     * @return list<Finding>
     */
    private static function deletedFindings(string $path, array $before, array $after, array &$relocations): array
    {
        if ($before === []) {
            return [];
        }

        $added = array_filter(
            $after,
            static fn (TestBody $test): bool => !isset($before[$test->name]),
        );
        $findings = [];

        foreach ($before as $name => $test) {
            if (isset($after[$name])) {
                continue;
            }

            $replacement = self::replacementFor($test, $added);
            if ($replacement !== null) {
                unset($added[$replacement], $relocations[$path . "\0" . $replacement]);
                continue;
            }

            $moved = self::movedTo($test, $path, $relocations);
            if ($moved !== null) {
                unset($relocations[$moved]);
                continue;
            }

            $findings[] = self::finding(
                $path,
                new TestBody($name, 1, $test->assertions, false, $test->trivial, $test->hash),
                'deleted',
                'weak',
                'medium',
                self::label($name) . ' was deleted without a comparably asserting replacement in the same file',
                ['assertions-before=' . $test->assertions],
                'Confirm that the covered behavior was removed intentionally or restore an equivalent test.',
            );
        }

        return $findings;
    }

    /**
     * A test whose body moved unchanged into another test file is relocated, not weakened.
     *
     * @param array<string,TestBody> $relocations
     */
    private static function movedTo(TestBody $removed, string $path, array $relocations): ?string
    {
        foreach ($relocations as $key => $candidate) {
            if (!str_starts_with($key, $path . "\0") && $candidate->hash === $removed->hash) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string,null|array<string,TestBody>> $beforeByPath
     * @param array<string,null|array<string,TestBody>> $afterByPath
     * @return array<string,TestBody>
     */
    private static function addedTests(array $beforeByPath, array $afterByPath): array
    {
        $added = [];

        foreach ($afterByPath as $path => $tests) {
            foreach ($tests ?? [] as $name => $test) {
                if (!isset($beforeByPath[$path][$name])) {
                    $added[$path . "\0" . $name] = $test;
                }
            }
        }

        return $added;
    }

    /**
     * @param array<string,TestBody> $added
     */
    private static function replacementFor(TestBody $removed, array $added): ?string
    {
        foreach ($added as $name => $candidate) {
            if ($candidate->hash === $removed->hash) {
                return $name;
            }
        }

        $needed = $removed->nonTrivialAssertions();
        foreach ($added as $name => $candidate) {
            if ($candidate->nonTrivialAssertions() >= $needed) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param list<string> $evidence
     */
    private static function finding(
        string $path,
        TestBody $test,
        string $kind,
        string $severity,
        string $confidence,
        string $message,
        array $evidence,
        string $suggestedAction,
    ): Finding {
        $fingerprint = hash('sha256', implode(':', [self::RULE_ID, $path, $test->name, $kind]));

        return new Finding(
            ruleId: self::RULE_ID,
            family: 'tests',
            severity: $severity,
            scope: 'file',
            message: $message,
            evidence: array_merge(['test=' . $test->name, 'kind=' . $kind], $evidence),
            score: $severity === 'weak' ? self::WEAK_SCORE : self::MEDIUM_SCORE,
            locations: [['path' => $path, 'line' => max(1, $test->line), 'column' => 1]],
            path: $path,
            deltaIdentity: [
                'fingerprintVersion' => 1,
                'occurrences' => [[
                    'fingerprint' => $fingerprint,
                    'path' => $path,
                    'line' => max(1, $test->line),
                    'column' => 1,
                ]],
            ],
            why: 'A test that checks less can make a change look safe while reducing the evidence that behavior still works.',
            suggestedAction: $suggestedAction,
            confidence: $confidence,
        );
    }

    private static function label(string $name): string
    {
        return str_contains($name, '::') ? $name . '()' : 'The test "' . $name . '"';
    }
}
