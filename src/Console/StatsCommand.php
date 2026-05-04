<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Support\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StatsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('stats')
            ->setDescription('Summarize slop findings by rule and file.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to scan when no report is provided.', '.')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Existing JSON report or baseline file.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON output.')
            ->addOption('config-file', null, InputOption::VALUE_REQUIRED, 'Read JSON config from this file (absolute or relative to the scan path).')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore path pattern.')
            ->addOption('top-rules', null, InputOption::VALUE_REQUIRED, 'How many rules to show.', '10')
            ->addOption('top-files', null, InputOption::VALUE_REQUIRED, 'How many files to show.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $this->stringArgument($input, 'path') ?? '.';
            $ignore = $this->stringListOption($input, 'ignore');
            $report = CommandSupport::reportInput(
                $this->stringOption($input, 'report'),
                $path,
                $ignore,
                $this->stringOption($input, 'config-file')
            );
            $stats = self::buildStats(
                $report,
                max(1, (int) ($this->stringOption($input, 'top-rules') ?? '10')),
                max(1, (int) ($this->stringOption($input, 'top-files') ?? '10'))
            );

            $output->writeln(
                (bool) $input->getOption('json')
                    ? Json::encode($stats, true)
                    : self::formatStats($stats)
            );

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            self::writeError($output, $exception->getMessage());

            return Command::FAILURE;
        }
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function buildStats(array $report, int $topRules, int $topFiles): array
    {
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $ruleCounts = [];
        $ruleLocations = [];
        $fileCounts = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $ruleId = is_string($finding['ruleId'] ?? null) ? $finding['ruleId'] : '<unknown>';
            $ruleCounts[$ruleId] = ($ruleCounts[$ruleId] ?? 0) + 1;

            $locations = is_array($finding['locations'] ?? null) ? $finding['locations'] : [];
            $ruleLocations[$ruleId] = ($ruleLocations[$ruleId] ?? 0) + max(1, count($locations));

            $path = is_string($finding['path'] ?? null) && $finding['path'] !== ''
                ? $finding['path']
                : (is_string($locations[0]['path'] ?? null) ? $locations[0]['path'] : '<repo>');
            $fileCounts[$path] = ($fileCounts[$path] ?? 0) + 1;
        }

        arsort($ruleCounts);
        arsort($fileCounts);

        $topRuleStats = [];
        foreach (array_slice($ruleCounts, 0, $topRules, true) as $ruleId => $count) {
            $topRuleStats[] = [
                'ruleId' => $ruleId,
                'findingCount' => $count,
                'locationCount' => $ruleLocations[$ruleId] ?? $count,
            ];
        }

        $topFileStats = [];
        foreach (array_slice($fileCounts, 0, $topFiles, true) as $path => $count) {
            $topFileStats[] = [
                'path' => $path,
                'findingCount' => $count,
            ];
        }

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        return [
            'summary' => [
                'findingCount' => count($findings),
                'uniqueRules' => count($ruleCounts),
                'uniquePaths' => count($fileCounts),
                'reportKind' => is_string($report['metadata']['kind'] ?? null) ? $report['metadata']['kind'] : 'scan',
                'reportFindingCount' => (int) ($summary['findingCount'] ?? count($findings)),
            ],
            'topRules' => $topRuleStats,
            'topFiles' => $topFileStats,
        ];
    }

    /** @param array<string,mixed> $stats */
    private static function formatStats(array $stats): string
    {
        $lines = [
            'slop-scan stats',
            'findings: ' . ($stats['summary']['findingCount'] ?? 0),
            'unique rules: ' . ($stats['summary']['uniqueRules'] ?? 0),
            'unique paths: ' . ($stats['summary']['uniquePaths'] ?? 0),
            'report kind: ' . ($stats['summary']['reportKind'] ?? 'scan'),
            '',
            'top rules:',
        ];

        foreach ($stats['topRules'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '- %s: %d findings, %d locations',
                (string) ($row['ruleId'] ?? '<unknown>'),
                (int) ($row['findingCount'] ?? 0),
                (int) ($row['locationCount'] ?? 0)
            );
        }

        $lines[] = '';
        $lines[] = 'top files:';

        foreach ($stats['topFiles'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lines[] = sprintf(
                '- %s: %d findings',
                (string) ($row['path'] ?? '<unknown>'),
                (int) ($row['findingCount'] ?? 0)
            );
        }

        return implode("\n", $lines);
    }

    private function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : null;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function stringListOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    private static function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }
}
