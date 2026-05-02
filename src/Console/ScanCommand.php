<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Analyzer;
use SlopScan\Baseline;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Delta;
use SlopScan\Reporter\GithubReporter;
use SlopScan\Support\Json;
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
            ->addOption('lint', null, InputOption::VALUE_NONE, 'Emit lint output.')
            ->addOption('github', null, InputOption::VALUE_NONE, 'Emit GitHub annotations.')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore path pattern.')
            ->addOption('cache-file', null, InputOption::VALUE_REQUIRED, 'Read and write a scan cache file.')
            ->addOption('baseline-file', null, InputOption::VALUE_REQUIRED, 'Read or write a baseline report file.')
            ->addOption('generate-baseline', null, InputOption::VALUE_NONE, 'Write the current scan as a baseline.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $target = (string) $input->getArgument('path');
            $ignore = $this->stringListOption($input, 'ignore');
            $config = Config::load($target);
            $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));
            $result = (new Analyzer())->analyze($target, $config, DefaultRegistry::create(), $this->stringOption($input, 'cache-file'));
            $baselineFile = $this->stringOption($input, 'baseline-file');

            if ((bool) $input->getOption('generate-baseline')) {
                if ($baselineFile === null || $baselineFile === '') {
                    throw new \InvalidArgumentException('Missing --baseline-file for --generate-baseline.');
                }

                Baseline::writeReport($baselineFile, Baseline::fromReport($result->toReport()));
                $output->writeln("slop-scan baseline written to {$baselineFile}");

                return Command::SUCCESS;
            }

            if ($baselineFile !== null && $baselineFile !== '') {
                $currentReport = $result->toReport();
                $baselineReport = Baseline::readReport($baselineFile);
                $delta = Delta::diff($baselineReport, $currentReport);
                $newFindings = Baseline::addedFindings($result->findings, $delta);

                if ((bool) $input->getOption('json')) {
                    $output->writeln(Json::encode(Baseline::reportWithDelta($currentReport, $delta), true));
                } elseif ((bool) $input->getOption('github')) {
                    $output->writeln(GithubReporter::renderFindings($newFindings));
                } elseif ((bool) $input->getOption('lint')) {
                    $output->writeln(CommandSupport::formatFindings($newFindings));
                } else {
                    $output->writeln(CommandSupport::formatDelta($delta));
                }

                return ($delta['summary']['added'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
            }

            $reporter = DefaultRegistry::create()->reporter(CommandSupport::scanReporterId(
                (bool) $input->getOption('json'),
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
