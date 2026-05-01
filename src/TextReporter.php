<?php

declare(strict_types=1);

namespace SlopScan;

final class TextReporter implements ReporterPlugin
{
    public function id(): string { return 'text'; }

    public function render(AnalysisResult $result): string
    {
        $summary = $result->summary;
        $lines = [
            'slop-scan report',
            'root: ' . $result->rootDir,
            'files scanned: ' . $summary['fileCount'],
            'directories scanned: ' . $summary['directoryCount'],
            'physical LOC: ' . $summary['physicalLineCount'],
            'logical LOC: ' . $summary['logicalLineCount'],
            'functions: ' . $summary['functionCount'],
            '',
            'Raw totals:',
            '- findings: ' . $summary['findingCount'],
            '- repo score: ' . number_format((float) $summary['repoScore'], 2, '.', ''),
        ];
        return implode("\n", $lines);
    }
}
