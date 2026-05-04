<?php

declare(strict_types=1);

namespace SlopScan\Reporter;

use SlopScan\Contract\ReporterPlugin;
use SlopScan\Model\AnalysisResult;
use SlopScan\Model\Finding;
use SlopScan\Support\Json;

final class NdjsonReporter implements ReporterPlugin
{
    public function id(): string { return 'ndjson'; }

    public function render(AnalysisResult $result): string
    {
        return self::renderReport($result->toReport());
    }

    /**
     * @param list<Finding> $findings
     * @param array<string,mixed> $summary
     */
    public static function renderFindings(array $findings, array $summary = []): string
    {
        $lines = [Json::encode([
            'type' => 'summary',
            'summary' => array_merge(['findingCount' => count($findings)], $summary),
        ])];

        foreach ($findings as $finding) {
            $lines[] = Json::encode([
                'type' => 'finding',
                'finding' => $finding->toReport(),
            ]);
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $report */
    public static function renderReport(array $report): string
    {
        $lines = [Json::encode([
            'type' => 'summary',
            'metadata' => $report['metadata'] ?? [],
            'rootDir' => $report['rootDir'] ?? null,
            'summary' => $report['summary'] ?? [],
        ])];

        foreach (($report['findings'] ?? []) as $finding) {
            $lines[] = Json::encode([
                'type' => 'finding',
                'finding' => $finding,
            ]);
        }

        return implode("\n", $lines);
    }
}
