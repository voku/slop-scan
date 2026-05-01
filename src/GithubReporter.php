<?php

declare(strict_types=1);

namespace SlopScan;

final class GithubReporter implements ReporterPlugin
{
    private const DEFAULT_LINE = 1;
    private const DEFAULT_COLUMN = 1;

    public function id(): string { return 'github'; }

    public function render(AnalysisResult $result): string
    {
        return self::renderFindings($result->findings);
    }

    /** @param list<Finding> $findings */
    public static function renderFindings(array $findings): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            foreach (self::locationsFor($finding) as $location) {
                $properties = [];
                $path = (string) ($location['path'] ?? '');
                if ($path !== '') {
                    $properties[] = 'file=' . self::escape($path);
                }
                $properties[] = 'line=' . self::escape((string) ($location['line'] ?? self::DEFAULT_LINE));
                $properties[] = 'col=' . self::escape((string) ($location['column'] ?? self::DEFAULT_COLUMN));
                $lines[] = '::error ' . implode(',', $properties) . '::' . self::escape($finding->message . ' (' . $finding->ruleId . ')');
            }
        }
        return implode("\n", $lines);
    }

    /** @return list<array{path:string,line:int,column:int}> */
    private static function locationsFor(Finding $finding): array
    {
        if ($finding->locations !== []) {
            return $finding->locations;
        }
        return [['path' => $finding->path ?? '', 'line' => self::DEFAULT_LINE, 'column' => self::DEFAULT_COLUMN]];
    }

    private static function escape(string $value): string
    {
        return str_replace(['%', "\r", "\n", ':', ',', '{', '}'], ['%25', '%0D', '%0A', '%3A', '%2C', '%7B', '%7D'], $value);
    }
}
