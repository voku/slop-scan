<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Model\AnalysisResult;
use SlopScan\Model\Finding;
use SlopScan\Reporter\LintReporter;
use SlopScan\Support\Json;
use SlopScan\Support\PatternMatcher;
use SlopScan\Support\ReportCodec;
use SlopScan\Support\ScanCache;

final class CommandSupport
{
    /**
     * @param list<string> $ignore
     * @return array<string,mixed>
     */
    public static function reportInput(?string $reportPath, ?string $targetPath, array $ignore, ?string $configFile = null): array
    {
        if ($reportPath !== null && $reportPath !== '') {
            return ReportCodec::readReport($reportPath);
        }

        if ($targetPath === null || $targetPath === '') {
            throw new \InvalidArgumentException('Missing delta input.');
        }

        $config = Config::load($targetPath, $configFile);
        $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));
        $scan = $config['scan'] ?? [];
        $cacheFile = ($scan['cacheFile'] ?? null) !== null
            ? self::resolveTargetPath($targetPath, $scan['cacheFile'])
            : ScanCache::defaultPath($targetPath);

        return (new Analyzer())->analyze($targetPath, $config, DefaultRegistry::create(), $cacheFile)->toReport();
    }

    /** @param array<string,mixed> $delta */
    public static function formatDelta(array $delta): string
    {
        $lines = ['slop-scan delta', 'added: ' . $delta['summary']['added'], 'resolved: ' . $delta['summary']['resolved']];

        foreach ($delta['changes'] as $change) {
            $lines[] = $change['status'] . ' ' . ($change['finding']['ruleId'] ?? '<unknown>');
        }

        return implode("\n", $lines);
    }

    /** @param list<Finding> $findings */
    public static function formatFindings(array $findings): string
    {
        if ($findings === []) {
            return '0 new findings';
        }

        return LintReporter::renderFindings($findings, 'new findings');
    }

    public static function scanReporterId(bool $json, bool $toon, bool $ndjson, bool $github, bool $lint): string
    {
        if ($json) {
            return 'json';
        }

        if ($toon) {
            return 'toon';
        }

        if ($ndjson) {
            return 'ndjson';
        }

        if ($github) {
            return 'github';
        }

        if ($lint) {
            return 'lint';
        }

        return 'text';
    }

    /** @param array<string,mixed> $delta */
    public static function shouldFail(array $delta, ?string $failOn): bool
    {
        if ($failOn === null || $failOn === '') {
            return false;
        }

        $statuses = array_map('trim', explode(',', $failOn));

        foreach ($delta['changes'] as $change) {
            if (in_array('any', $statuses, true) || in_array($change['status'], $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     */
    public static function filteredResult(AnalysisResult $result, array $selection): AnalysisResult
    {
        $findings = self::filterFindingObjects($result->findings, $selection);

        return new AnalysisResult(
            $result->rootDir,
            $result->config,
            self::filteredSummary($result, $findings),
            $result->files,
            $result->directories,
            $findings,
            self::scoreBucketsForFindings($findings),
            self::directoryScoreBucketsForFindings($findings),
            array_reduce($findings, static fn(float $sum, Finding $finding): float => $sum + $finding->score, 0.0),
        );
    }

    /**
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     * @return array<string,mixed>
     */
    public static function reportFromResult(AnalysisResult $result, array $selection, ?int $originalFindingCount = null): array
    {
        $report = $result->toReport();
        $filters = self::reportableSelection($selection);
        if ($filters !== []) {
            $report['metadata']['appliedFilters'] = $filters;
            $report['summary']['findingCountBeforeFilters'] = $originalFindingCount ?? $result->summary['findingCount'];
        }
        $report['metadata']['configHash'] = hash('sha256', Json::encode($report['config']));

        return $report;
    }

    /**
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     * @return list<Finding>
     */
    private static function filterFindingObjects(array $findings, array $selection): array
    {
        $filtered = array_values(array_filter($findings, static fn(Finding $finding): bool => self::matchesFinding($finding, $selection)));
        usort($filtered, static function (Finding $left, Finding $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) {
                return $score;
            }

            $rule = strcmp($left->ruleId, $right->ruleId);
            if ($rule !== 0) {
                return $rule;
            }

            $leftPath = self::findingSortPath($left);
            $rightPath = self::findingSortPath($right);
            $path = strcmp($leftPath, $rightPath);
            if ($path !== 0) {
                return $path;
            }

            return self::findingSortLine($left) <=> self::findingSortLine($right);
        });

        if ($selection['maxFindings'] !== null) {
            $filtered = array_slice($filtered, 0, $selection['maxFindings']);
        }

        return $filtered;
    }

    /**
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     */
    private static function matchesFinding(Finding $finding, array $selection): bool
    {
        if ($selection['rules'] !== [] && !in_array($finding->ruleId, $selection['rules'], true)) {
            return false;
        }

        if ($selection['minScore'] !== null && $finding->score < $selection['minScore']) {
            return false;
        }

        if ($selection['paths'] === []) {
            return true;
        }

        return self::matchesAnyPath(self::candidatePaths($finding->path, $finding->locations), $selection['paths']);
    }

    /**
     * @param list<string> $candidatePaths
     * @param list<string> $patterns
     */
    private static function matchesAnyPath(array $candidatePaths, array $patterns): bool
    {
        foreach ($candidatePaths as $candidatePath) {
            foreach ($patterns as $pattern) {
                if (PatternMatcher::matches($candidatePath, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{path:string,line:int,column?:int}> $locations
     * @return list<string>
     */
    private static function candidatePaths(?string $path, array $locations): array
    {
        $paths = [];
        if (is_string($path) && $path !== '') {
            $paths[] = $path;
        }
        foreach ($locations as $location) {
            if ($location['path'] !== '') {
                $paths[] = $location['path'];
            }
        }

        return array_values(array_unique($paths));
    }

    private static function findingSortPath(Finding $finding): string
    {
        return self::candidatePaths($finding->path, $finding->locations)[0] ?? '';
    }

    private static function findingSortLine(Finding $finding): int
    {
        return (int) ($finding->locations[0]['line'] ?? 0);
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{path:string,score:float,findingCount:int}>
     */
    private static function scoreBucketsForFindings(array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            foreach (self::candidatePaths($finding->path, $finding->locations) as $path) {
                $scores[$path]['score'] = ($scores[$path]['score'] ?? 0.0) + $finding->score;
                $scores[$path]['findingCount'] = ($scores[$path]['findingCount'] ?? 0) + 1;
            }
        }

        return self::normalizedScoreBuckets($scores);
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{path:string,score:float,findingCount:int}>
     */
    private static function directoryScoreBucketsForFindings(array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            foreach (self::candidatePaths($finding->path, $finding->locations) as $path) {
                $directory = str_replace('\\', '/', dirname($path));
                $scores[$directory]['score'] = ($scores[$directory]['score'] ?? 0.0) + $finding->score;
                $scores[$directory]['findingCount'] = ($scores[$directory]['findingCount'] ?? 0) + 1;
            }
        }

        return self::normalizedScoreBuckets($scores);
    }

    /**
     * @param array<string,array{score:float,findingCount:int}> $scores
     * @return list<array{path:string,score:float,findingCount:int}>
     */
    private static function normalizedScoreBuckets(array $scores): array
    {
        $rows = [];
        foreach ($scores as $path => $bucket) {
            $rows[] = [
                'path' => $path,
                'score' => (float) $bucket['score'],
                'findingCount' => (int) $bucket['findingCount'],
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) {
                return $score;
            }

            $count = $right['findingCount'] <=> $left['findingCount'];
            if ($count !== 0) {
                return $count;
            }

            return strcmp($left['path'], $right['path']);
        });

        return $rows;
    }

    /**
     * @param list<Finding> $findings
     * @return array<string,mixed>
     */
    private static function filteredSummary(AnalysisResult $result, array $findings): array
    {
        $summary = $result->summary;
        $fileCount = (int) ($summary['fileCount'] ?? 0);
        $logicalLineCount = (int) ($summary['logicalLineCount'] ?? 0);
        $functionCount = (int) ($summary['functionCount'] ?? 0);
        $repoScore = array_reduce($findings, static fn(float $sum, Finding $finding): float => $sum + $finding->score, 0.0);
        $findingCount = count($findings);
        $kloc = $logicalLineCount / 1000;

        $summary['findingCount'] = $findingCount;
        $summary['repoScore'] = $repoScore;
        $summary['normalized'] = [
            'scorePerFile' => self::divide($repoScore, $fileCount),
            'scorePerKloc' => self::divide($repoScore, $kloc),
            'scorePerFunction' => self::divide($repoScore, $functionCount),
            'findingsPerFile' => self::divide($findingCount, $fileCount),
            'findingsPerKloc' => self::divide($findingCount, $kloc),
            'findingsPerFunction' => self::divide($findingCount, $functionCount),
        ];

        return $summary;
    }

    private static function divide(float|int $numerator, float|int $denominator): ?float
    {
        if ($denominator === 0 || $denominator === 0.0) {
            return null;
        }

        return (float) $numerator / (float) $denominator;
    }

    public static function resolveTargetPath(string $targetPath, string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return (realpath($targetPath) ?: $targetPath) . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     * @return array<string,mixed>
     */
    private static function reportableSelection(array $selection): array
    {
        $filters = [];
        if ($selection['rules'] !== []) {
            $filters['rules'] = $selection['rules'];
        }
        if ($selection['paths'] !== []) {
            $filters['paths'] = $selection['paths'];
        }
        if ($selection['maxFindings'] !== null) {
            $filters['maxFindings'] = $selection['maxFindings'];
        }
        if ($selection['minScore'] !== null) {
            $filters['minScore'] = $selection['minScore'];
        }

        return $filters;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('~^[A-Za-z]:[\\\\/]~', $path);
    }
}
