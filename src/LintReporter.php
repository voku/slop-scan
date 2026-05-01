<?php

declare(strict_types=1);

namespace SlopScan;

final class LintReporter implements ReporterPlugin
{
    public function id(): string { return 'lint'; }

    public function render(AnalysisResult $result): string
    {
        return self::renderFindings($result->findings, 'findings');
    }

    /** @param list<Finding> $findings */
    public static function renderFindings(array $findings, string $label = 'findings'): string
    {
        if ($findings === []) {
            return '0 findings';
        }
        $lines = [];
        foreach ($findings as $finding) {
            $lines[] = "{$finding->severity}  {$finding->message}  {$finding->ruleId}";
            foreach (array_slice($finding->locations, 0, 3) as $location) {
                $lines[] = '  at ' . $location['path'] . ':' . $location['line'] . ':' . ($location['column'] ?? 1);
            }
            $lines[] = '';
        }
        $count = count($findings);
        $lines[] = $count . ' ' . ($count === 1 ? rtrim($label, 's') : $label);
        return rtrim(implode("\n", $lines));
    }
}
