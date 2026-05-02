<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Model\Finding;
use SlopScan\Reporter\LintReporter;
use SlopScan\Support\ScanCache;

final class CommandSupport
{
    /**
     * @param list<string> $ignore
     * @return array<string,mixed>
     */
    public static function reportInput(?string $reportPath, ?string $targetPath, array $ignore): array
    {
        if ($reportPath !== null && $reportPath !== '') {
            return json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        }

        if ($targetPath === null || $targetPath === '') {
            throw new \InvalidArgumentException('Missing delta input.');
        }

        $config = Config::load($targetPath);
        $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));

        return (new Analyzer())->analyze($targetPath, $config, DefaultRegistry::create(), ScanCache::defaultPath($targetPath))->toReport();
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

    public static function scanReporterId(bool $json, bool $github, bool $lint): string
    {
        if ($json) {
            return 'json';
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
}
