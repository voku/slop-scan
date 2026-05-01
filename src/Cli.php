<?php

declare(strict_types=1);

namespace SlopScan;

final class Cli
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            $args = self::parse($argv);
            if ($args['help'] || $argv === []) {
                fwrite(STDOUT, self::help() . "\n");
                return 0;
            }
            if ($args['command'] === 'scan') {
                $config = Config::load($args['target']);
                $config['ignores'] = array_values(array_merge($config['ignores'], $args['ignore']));
                $result = (new Analyzer())->analyze($args['target'], $config, DefaultRegistry::create());
                if ($args['generateBaseline']) {
                    if ($args['baselineFile'] === null || $args['baselineFile'] === '') {
                        throw new \InvalidArgumentException('Missing --baseline-file for --generate-baseline.');
                    }
                    Baseline::writeReport($args['baselineFile'], $result->toReport());
                    fwrite(STDOUT, "slop-scan baseline written to {$args['baselineFile']}\n");
                    return 0;
                }
                if ($args['baselineFile'] !== null && $args['baselineFile'] !== '') {
                    $currentReport = $result->toReport();
                    $baselineReport = Baseline::readReport($args['baselineFile']);
                    $delta = Delta::diff($baselineReport, $currentReport);
                    $newFindings = Baseline::addedFindings($result->findings, $delta);
                    if ($args['json']) {
                        fwrite(STDOUT, Json::encode(Baseline::reportWithDelta($currentReport, $delta), true) . "\n");
                    } elseif ($args['github']) {
                        fwrite(STDOUT, GithubReporter::renderFindings($newFindings) . "\n");
                    } elseif ($args['lint']) {
                        fwrite(STDOUT, self::formatFindings($newFindings) . "\n");
                    } else {
                        fwrite(STDOUT, self::formatDelta($delta) . "\n");
                    }
                    return ($delta['summary']['added'] ?? 0) > 0 ? 1 : 0;
                }
                $reporter = DefaultRegistry::create()->reporter(self::scanReporterId($args));
                fwrite(STDOUT, $reporter->render($result) . "\n");
                return 0;
            }
            if ($args['command'] === 'delta') {
                $base = self::reportInput($args['baseReport'], $args['base'], $args['ignore']);
                $head = self::reportInput($args['headReport'], $args['head'] ?: '.', $args['ignore']);
                $delta = Delta::diff($base, $head);
                fwrite(STDOUT, $args['json'] ? Json::encode($delta, true) . "\n" : self::formatDelta($delta) . "\n");
                return self::shouldFail($delta, $args['failOn']) ? 1 : 0;
            }
            fwrite(STDERR, "Unknown command: {$args['command']}\n");
            return 1;
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");
            return 1;
        }
    }

    public static function help(): string
    {
        return implode("\n", ['slop-scan', '', 'Usage:', '  slop-scan scan [path] [options]', '  slop-scan delta [base-path] [head-path] [options]', '  slop-scan --help']);
    }

    /** @param list<string> $argv @return array<string,mixed> */
    private static function parse(array $argv): array
    {
        $args = [
            'help' => false,
            'json' => false,
            'lint' => false,
            'github' => false,
            'generateBaseline' => false,
            'baselineFile' => null,
            'ignore' => [],
            'command' => null,
            'target' => '.',
            'base' => null,
            'head' => null,
            'baseReport' => null,
            'headReport' => null,
            'failOn' => null,
        ];
        $optionValueKeys = [
            '--ignore' => 'ignore',
            '--base' => 'base',
            '--head' => 'head',
            '--base-report' => 'baseReport',
            '--head-report' => 'headReport',
            '--fail-on' => 'failOn',
            '--baseline-file' => 'baselineFile',
        ];
        $positionals = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--help' || $arg === '-h') {
                $args['help'] = true;
            } elseif ($arg === '--json') {
                $args['json'] = true;
            } elseif ($arg === '--lint') {
                $args['lint'] = true;
            } elseif ($arg === '--github') {
                $args['github'] = true;
            } elseif ($arg === '--generate-baseline') {
                $args['generateBaseline'] = true;
            } elseif (isset($optionValueKeys[$arg])) {
                $value = $argv[++$i] ?? throw new \InvalidArgumentException("Missing value for {$arg}");
                $key = $optionValueKeys[$arg];
                if ($key === 'ignore') {
                    $args['ignore'][] = $value;
                } else {
                    $args[$key] = $value;
                }
            } else {
                $positionals[] = $arg;
            }
        }
        $args['command'] = $positionals[0] ?? null;
        if ($args['command'] === 'scan') {
            $args['target'] = $positionals[1] ?? '.';
        } elseif ($args['command'] === 'delta') {
            $args['base'] ??= $positionals[1] ?? null;
            $args['head'] ??= $positionals[2] ?? '.';
        }
        return $args;
    }

    /** @param list<string> $ignore @return array<string,mixed> */
    private static function reportInput(?string $reportPath, ?string $targetPath, array $ignore): array
    {
        if ($reportPath !== null) {
            return json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        }
        if ($targetPath === null) {
            throw new \InvalidArgumentException('Missing delta input.');
        }
        $config = Config::load($targetPath);
        $config['ignores'] = array_values(array_merge($config['ignores'], $ignore));
        return (new Analyzer())->analyze($targetPath, $config, DefaultRegistry::create())->toReport();
    }

    /** @param array<string,mixed> $delta */
    private static function formatDelta(array $delta): string
    {
        $lines = ['slop-scan delta', 'added: ' . $delta['summary']['added'], 'resolved: ' . $delta['summary']['resolved']];
        foreach ($delta['changes'] as $change) {
            $lines[] = $change['status'] . ' ' . ($change['finding']['ruleId'] ?? '<unknown>');
        }
        return implode("\n", $lines);
    }

    /** @param list<Finding> $findings */
    private static function formatFindings(array $findings): string
    {
        if ($findings === []) {
            return '0 new findings';
        }
        return LintReporter::renderFindings($findings, 'new findings');
    }

    /** @param array<string,mixed> $args */
    private static function scanReporterId(array $args): string
    {
        if ($args['json']) {
            return 'json';
        }
        if ($args['github']) {
            return 'github';
        }
        if ($args['lint']) {
            return 'lint';
        }
        return 'text';
    }

    /** @param array<string,mixed> $delta */
    private static function shouldFail(array $delta, ?string $failOn): bool
    {
        if ($failOn === null || $failOn === '') {
            return false;
        }
        $statuses = array_map('trim', explode(',', $failOn));
        foreach ($delta['changes'] as $change) {
            if (in_array('any', $statuses, true) || in_array($change['status'], $statuses, true)) {
                return true;
            }
        }
        return false;
    }
}
