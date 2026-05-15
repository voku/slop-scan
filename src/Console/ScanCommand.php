<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Analyzer;
use SlopScan\Baseline;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Delta;
use SlopScan\Reporter\GithubReporter;
use SlopScan\Reporter\NdjsonReporter;
use SlopScan\Support\Json;
use SlopScan\Support\ReportCodec;
use SlopScan\Support\ScanCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('scan')
            ->setDescription('Scan a repository and report slop findings.')
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to scan.', '.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON output.')
            ->addOption('toon', null, InputOption::VALUE_NONE, 'Emit TOON output.')
            ->addOption('ndjson', null, InputOption::VALUE_NONE, 'Emit newline-delimited JSON output.')
            ->addOption('lint', null, InputOption::VALUE_NONE, 'Emit lint output.')
            ->addOption('github', null, InputOption::VALUE_NONE, 'Emit GitHub annotations.')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore path pattern.')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only include findings for these rule identifiers.')
            ->addOption('path-filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only include findings whose path matches these glob patterns.')
            ->addOption('max-findings', null, InputOption::VALUE_REQUIRED, 'Limit the number of findings after filtering.')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Only include findings at or above this score.')
            ->addOption('config-file', null, InputOption::VALUE_REQUIRED, 'Read JSON config from this file (absolute or relative to the scan path).')
            ->addOption('cache-file', null, InputOption::VALUE_REQUIRED, 'Read and write a scan cache file.')
            ->addOption('baseline-file', null, InputOption::VALUE_REQUIRED, 'Read or write a baseline report file.')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current scan as a baseline.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $target = (string) $input->getArgument('path');
            $ignore = $this->stringListOption($input, 'ignore');
            $config = Config::load($target, $this->stringOption($input, 'config-file'));
            $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));
            $selection = $this->selection($input, $config);
            $rawResult = (new Analyzer())->analyze($target, $config, DefaultRegistry::create(), $this->cacheFile($target, $input, $config));
            $originalFindingCount = (int) $rawResult->summary['findingCount'];
            $result = CommandSupport::filteredResult($rawResult, $selection);
            $baselineFile = $this->baselineFile($target, $input, $config);

            if ((bool) $input->getOption('generate-baseline')) {
                if ($baselineFile === null || $baselineFile === '') {
                    throw new \InvalidArgumentException('Missing --baseline-file for --generate-baseline.');
                }

                Baseline::writeReport($baselineFile, Baseline::fromReport(
                    CommandSupport::reportFromResult($result, $selection, $originalFindingCount)
                ));
                $output->writeln("slop-scan baseline written to {$baselineFile}");

                return Command::SUCCESS;
            }

            if ($baselineFile !== null && $baselineFile !== '') {
                $currentReport = CommandSupport::reportFromResult($result, $selection, $originalFindingCount);
                $baselineReport = Baseline::readReport($baselineFile);
                $delta = Delta::diff($baselineReport, $currentReport);
                $newFindings = Baseline::addedFindings($result->findings, $delta);

                if ((bool) $input->getOption('json')) {
                    $output->writeln(Json::encode(Baseline::reportWithDelta($currentReport, $delta), true));
                } elseif ((bool) $input->getOption('toon')) {
                    $output->writeln(ReportCodec::encodeReport(Baseline::reportWithDelta($currentReport, $delta), 'toon'));
                } elseif ((bool) $input->getOption('ndjson')) {
                    $output->writeln(NdjsonReporter::renderFindings($newFindings, [
                        'label' => 'new findings',
                        'delta' => $delta['summary'],
                    ]));
                } elseif ((bool) $input->getOption('github')) {
                    $output->writeln(GithubReporter::renderFindings($newFindings));
                } elseif ((bool) $input->getOption('lint')) {
                    $output->writeln(CommandSupport::formatFindings($newFindings));
                } else {
                    $output->writeln(CommandSupport::formatDelta($delta));
                }

                return ($delta['summary']['added'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
            }

            if ((bool) $input->getOption('json')) {
                $output->writeln(Json::encode(CommandSupport::reportFromResult($result, $selection, $originalFindingCount), true));

                return Command::SUCCESS;
            }

            if ((bool) $input->getOption('toon')) {
                $output->writeln(ReportCodec::encodeReport(
                    CommandSupport::reportFromResult($result, $selection, $originalFindingCount),
                    'toon'
                ));

                return Command::SUCCESS;
            }

            if ((bool) $input->getOption('ndjson')) {
                $output->writeln(NdjsonReporter::renderReport(
                    CommandSupport::reportFromResult($result, $selection, $originalFindingCount)
                ));

                return Command::SUCCESS;
            }

            $reporter = DefaultRegistry::create()->reporter(CommandSupport::scanReporterId(
                false,
                false,
                false,
                (bool) $input->getOption('github'),
                (bool) $input->getOption('lint'),
            ));

            $output->writeln($reporter->render($result));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            self::writeError($output, $exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float}
     */
    private function selection(InputInterface $input, array $config): array
    {
        $maxFindings = $this->stringOption($input, 'max-findings');
        $minScore = $this->stringOption($input, 'min-score');
        $scan = $config['scan'] ?? [];

        return [
            'rules' => $this->firstNonEmptyList($this->stringListOption($input, 'rule'), $scan['rules'] ?? []),
            'paths' => $this->firstNonEmptyList($this->stringListOption($input, 'path-filter'), $scan['pathFilters'] ?? []),
            'maxFindings' => $maxFindings !== null && $maxFindings !== ''
                ? max(0, (int) $maxFindings)
                : ($scan['maxFindings'] ?? null),
            'minScore' => $minScore !== null && $minScore !== ''
                ? (float) $minScore
                : ($scan['minScore'] ?? null),
        ];
    }

    private function cacheFile(string $target, InputInterface $input, array $config): string
    {
        $explicit = $this->stringOption($input, 'cache-file');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $scan = $config['scan'] ?? [];
        $configured = $scan['cacheFile'] ?? null;

        return $configured !== null
            ? CommandSupport::resolveTargetPath($target, $configured)
            : ScanCache::defaultPath($target);
    }

    private function baselineFile(string $target, InputInterface $input, array $config): ?string
    {
        $explicit = $this->stringOption($input, 'baseline-file');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $scan = $config['scan'] ?? [];
        $configured = $scan['baselineFile'] ?? null;

        return $configured !== null
            ? CommandSupport::resolveTargetPath($target, $configured)
            : null;
    }

    /** @param list<string> $primary @param list<string> $fallback @return list<string> */
    private function firstNonEmptyList(array $primary, array $fallback): array
    {
        return $primary !== [] ? $primary : $fallback;
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
