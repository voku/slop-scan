<?php

declare(strict_types=1);

namespace SlopScan\Console;

use SlopScan\Delta;
use SlopScan\Support\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DeltaCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('delta')
            ->setDescription('Compare two scan inputs and report the delta.')
            ->addArgument('base-path', InputArgument::OPTIONAL, 'Base path to compare.')
            ->addArgument('head-path', InputArgument::OPTIONAL, 'Head path to compare.', '.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON output.')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore path pattern.')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base path to compare.')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'Head path to compare.')
            ->addOption('base-config-file', null, InputOption::VALUE_REQUIRED, 'Read base JSON config from this file (absolute or relative to the base path).')
            ->addOption('head-config-file', null, InputOption::VALUE_REQUIRED, 'Read head JSON config from this file (absolute or relative to the head path).')
            ->addOption('base-report', null, InputOption::VALUE_REQUIRED, 'Existing base report file.')
            ->addOption('head-report', null, InputOption::VALUE_REQUIRED, 'Existing head report file.')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Comma-separated delta statuses that should fail.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $ignore = $this->stringListOption($input, 'ignore');
            $base = $this->stringOption($input, 'base') ?? $this->stringArgument($input, 'base-path');
            $head = $this->stringOption($input, 'head') ?? $this->stringArgument($input, 'head-path') ?? '.';
            $delta = Delta::diff(
                CommandSupport::reportInput(
                    $this->stringOption($input, 'base-report'),
                    $base,
                    $ignore,
                    $this->stringOption($input, 'base-config-file')
                ),
                CommandSupport::reportInput(
                    $this->stringOption($input, 'head-report'),
                    $head,
                    $ignore,
                    $this->stringOption($input, 'head-config-file')
                ),
            );

            $output->writeln(
                (bool) $input->getOption('json')
                    ? Json::encode($delta, true)
                    : CommandSupport::formatDelta($delta)
            );

            return CommandSupport::shouldFail($delta, $this->stringOption($input, 'fail-on'))
                ? Command::FAILURE
                : Command::SUCCESS;
        } catch (\Throwable $exception) {
            self::writeError($output, $exception->getMessage());

            return Command::FAILURE;
        }
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
