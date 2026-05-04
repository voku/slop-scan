<?php

declare(strict_types=1);

namespace SlopScan\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SlopScanApplication extends Application
{
    public function __construct()
    {
        parent::__construct('slop-scan');

        $this->add(new ScanCommand());
        $this->add(new DeltaCommand());
        $this->add(new StatsCommand());
    }

    public function renderThrowable(\Throwable $exception, OutputInterface $output): void
    {
        $message = $exception instanceof CommandNotFoundException
            ? $this->unknownCommandMessage($exception)
            : $exception->getMessage();

        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }

    private function unknownCommandMessage(CommandNotFoundException $exception): string
    {
        if (preg_match('/Command "([^"]+)"/', $exception->getMessage(), $matches) === 1) {
            return 'Unknown command: ' . $matches[1];
        }

        return $exception->getMessage();
    }
}
