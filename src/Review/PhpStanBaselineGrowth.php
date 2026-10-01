<?php

declare(strict_types=1);

namespace SlopScan\Review;

use SlopScan\Model\Finding;

/**
 * Delta-only review evidence: which source files gained newly suppressed PHPStan errors.
 * It compares two trees and never runs PHPStan or rewrites the baseline.
 */
final class PhpStanBaselineGrowth
{
    public const RULE_ID = 'php.static-analysis-baseline-growth';

    private const DEFAULT_BASELINE = 'phpstan-baseline.neon';
    private const CONFIG_FILES = ['phpstan.neon', 'phpstan.neon.dist'];
    private const GROWTH_SCORE = 1.5;
    private const INTRODUCED_SCORE = 2.0;

    /**
     * @return list<Finding>
     * @throws \InvalidArgumentException when a baseline that exists cannot be read as a PHPStan baseline
     */
    public static function comparePaths(string $basePath, string $headPath): array
    {
        $before = self::baselines($basePath);
        $after = self::baselines($headPath);
        $findings = [];

        foreach ($after as $baselinePath => $headCounts) {
            if (!array_key_exists($baselinePath, $before)) {
                $findings[] = self::introduced($baselinePath, $headCounts, $headPath);
                continue;
            }

            foreach ($headCounts as $sourcePath => $count) {
                $added = $count - ($before[$baselinePath][$sourcePath] ?? 0);
                if ($added > 0) {
                    $findings[] = self::grown($baselinePath, $sourcePath, $added, $count, $headPath);
                }
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
     * @return array<string,array<string,int>> suppressed error counts per source path, per baseline path
     */
    private static function baselines(string $root): array
    {
        $baselines = [];
        foreach (self::baselinePaths($root) as $relative) {
            $contents = file_get_contents($root . '/' . $relative);
            if ($contents === false) {
                throw new \InvalidArgumentException(sprintf('Unable to read PHPStan baseline "%s".', $relative));
            }

            try {
                $baselines[$relative] = PhpStanBaseline::countsByPath($contents, self::directoryOf($relative));
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException(sprintf('%s (%s)', $exception->getMessage(), $relative), 0, $exception);
            }
        }

        ksort($baselines, SORT_STRING);

        return $baselines;
    }

    /** @return list<string> */
    private static function baselinePaths(string $root): array
    {
        $candidates = [self::DEFAULT_BASELINE];
        foreach (self::CONFIG_FILES as $configFile) {
            $config = @file_get_contents($root . '/' . $configFile);
            if ($config === false) {
                continue;
            }

            preg_match_all('~^\s*-\s*[\'"]?([^\s\'"#]*baseline[^\s\'"#]*\.neon)[\'"]?\s*$~mi', $config, $matches);
            foreach ($matches[1] as $include) {
                $candidates[] = ltrim(str_replace('\\', '/', $include), '/');
            }
        }

        $paths = [];
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($root . '/' . $candidate)) {
                $paths[] = $candidate;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function directoryOf(string $relativePath): string
    {
        $directory = dirname($relativePath);

        return $directory === '.' ? '' : $directory;
    }

    /** @param array<string,int> $counts */
    private static function introduced(string $baselinePath, array $counts, string $headPath): Finding
    {
        $total = array_sum($counts);

        return self::finding(
            $baselinePath,
            $baselinePath,
            'introduced',
            self::INTRODUCED_SCORE,
            sprintf('%s is new and accepts %d existing PHPStan error%s', $baselinePath, $total, $total === 1 ? '' : 's'),
            ['baseline=' . $baselinePath, 'baseline-introduced=true', 'added-entries=' . $total, 'source-files=' . count($counts)],
            $headPath,
        );
    }

    private static function grown(string $baselinePath, string $sourcePath, int $added, int $total, string $headPath): Finding
    {
        return self::finding(
            $sourcePath,
            $baselinePath,
            'growth:' . $sourcePath,
            self::GROWTH_SCORE,
            sprintf('%s gained %d suppressed PHPStan error%s in %s', $sourcePath, $added, $added === 1 ? '' : 's', $baselinePath),
            ['baseline=' . $baselinePath, 'source=' . $sourcePath, 'baseline-introduced=false', 'added-entries=' . $added, 'entries-now=' . $total],
            $headPath,
        );
    }

    /** @param list<string> $evidence */
    private static function finding(
        string $path,
        string $baselinePath,
        string $kind,
        float $score,
        string $message,
        array $evidence,
        string $headPath,
    ): Finding {
        $locationPath = is_file($headPath . '/' . $path) ? $path : $baselinePath;
        $fingerprint = hash('sha256', implode(':', [self::RULE_ID, $baselinePath, $kind]));

        return new Finding(
            ruleId: self::RULE_ID,
            family: 'static-analysis',
            severity: 'medium',
            scope: 'file',
            message: $message,
            evidence: $evidence,
            score: $score,
            locations: [['path' => $locationPath, 'line' => 1, 'column' => 1]],
            path: $locationPath,
            deltaIdentity: [
                'fingerprintVersion' => 1,
                'occurrences' => [[
                    'fingerprint' => $fingerprint,
                    'path' => $locationPath,
                    'line' => 1,
                    'column' => 1,
                ]],
            ],
            why: 'A larger PHPStan baseline hides errors that the analyser reported before, so the change looks cleaner to the analyser than it is.',
            suggestedAction: 'Confirm the suppressed errors are intentional or fix them instead of baselining; this is review evidence, not a verdict that baselines are wrong.',
            confidence: 'high',
        );
    }
}
