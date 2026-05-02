<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Model\Finding;
use SlopScan\Support\Json;

final class Baseline
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function fromReport(array $report): array
    {
        $metadata = $report['metadata'] ?? [];

        return [
            'metadata' => [
                'schemaVersion' => $metadata['schemaVersion'] ?? 1,
                'kind' => 'baseline',
                'tool' => $metadata['tool'] ?? ['name' => 'slop-scan-php', 'version' => '0.1.0'],
                'configHash' => $metadata['configHash'] ?? '',
                'findingFingerprintVersion' => $metadata['findingFingerprintVersion'] ?? 1,
            ],
            'summary' => [
                'findingCount' => count($report['findings'] ?? []),
            ],
            'findings' => array_values($report['findings'] ?? []),
        ];
    }

    public static function readReport(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Baseline file does not exist: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['findings']) || !is_array($decoded['findings'])) {
            throw new \InvalidArgumentException("Baseline file is not a slop-scan JSON report: {$path}");
        }
        return $decoded;
    }

    public static function writeReport(string $path, array $report): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Baseline directory does not exist: {$directory}");
        }
        if (file_put_contents($path, Json::encode($report, true) . "\n") === false) {
            throw new \RuntimeException("Unable to write baseline file: {$path}");
        }
    }

    /**
     * @param list<Finding>       $findings
     * @param array<string,mixed> $delta
     * @return list<Finding>
     */
    public static function addedFindings(array $findings, array $delta): array
    {
        $added = [];
        foreach ($delta['changes'] ?? [] as $change) {
            if (($change['status'] ?? null) === 'added') {
                $added[(string) ($change['fingerprint'] ?? '')] = true;
            }
        }
        if ($added === []) {
            return [];
        }
        $newFindings = [];
        foreach ($findings as $finding) {
            if (self::matchesAnyFingerprint($finding, $added)) {
                $newFindings[] = $finding;
            }
        }
        return $newFindings;
    }

    /** @param array<string,bool> $fingerprints */
    private static function matchesAnyFingerprint(Finding $finding, array $fingerprints): bool
    {
        foreach (($finding->deltaIdentity['occurrences'] ?? []) as $occurrence) {
            if (isset($fingerprints[(string) ($occurrence['fingerprint'] ?? '')])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $delta
     * @return array<string,mixed>
     */
    public static function reportWithDelta(array $report, array $delta): array
    {
        $report['baseline'] = [
            'summary' => $delta['summary'] ?? ['added' => 0, 'resolved' => 0],
            'changes' => $delta['changes'] ?? [],
        ];
        $report['newFindings'] = [];
        foreach ($delta['changes'] ?? [] as $change) {
            if (($change['status'] ?? null) === 'added') {
                $report['newFindings'][] = $change['finding'];
            }
        }
        return $report;
    }
}
