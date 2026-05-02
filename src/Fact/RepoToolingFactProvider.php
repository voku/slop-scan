<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use SlopScan\Contract\FactProvider;
use SlopScan\Runtime\ProviderContext;

final class RepoToolingFactProvider implements FactProvider
{
    /** @var list<string> */
    private const INFECTION_CONFIG_FILES = [
        'infection.json5',
        'infection.json',
        'infection.json5.dist',
        'infection.json.dist',
    ];

    public function id(): string { return 'repo.tooling'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return []; }
    public function provides(): array { return ['repo.infectionStaticAnalysis']; }
    public function supports(ProviderContext $context): bool { return true; }

    public function run(ProviderContext $context): array
    {
        return [
            'repo.infectionStaticAnalysis' => self::infectionStaticAnalysisSummary($context->runtime->rootDir),
        ];
    }

    /**
     * @return array{
     *     usesInfection:bool,
     *     hasStaticAnalysisIntegration:bool,
     *     configPath:?string,
     *     locationPath:?string,
     *     evidence:list<string>
     * }
     */
    private static function infectionStaticAnalysisSummary(string $rootDir): array
    {
        $usesInfection = false;
        $hasStaticAnalysisIntegration = false;
        $configPath = null;
        $locationPath = null;
        $evidence = [];

        $composerPath = $rootDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerPath)) {
            $composer = self::readJsonFile($composerPath);
            if ($composer !== null) {
                if (self::composerUsesInfection($composer)) {
                    $usesInfection = true;
                    $locationPath ??= 'composer.json';
                    $evidence[] = 'composer.json requires infection/infection';
                }

                foreach (self::composerScriptCommands($composer) as $scriptName => $commands) {
                    foreach ($commands as $command) {
                        if (!self::mentionsInfection($command)) {
                            continue;
                        }

                        $usesInfection = true;
                        $locationPath ??= 'composer.json';
                        $evidence[] = 'composer script "' . $scriptName . '" runs Infection';

                        $tool = self::staticAnalysisToolFromText($command);
                        if ($tool !== null) {
                            $hasStaticAnalysisIntegration = true;
                            $evidence[] = 'composer script "' . $scriptName . '" uses --static-analysis-tool=' . $tool;
                        }
                    }
                }
            }
        }

        foreach (self::INFECTION_CONFIG_FILES as $candidate) {
            $absolutePath = $rootDir . DIRECTORY_SEPARATOR . $candidate;
            if (!is_file($absolutePath)) {
                continue;
            }

            $usesInfection = true;
            $configPath = $candidate;
            $locationPath = $candidate;
            $evidence[] = 'found ' . $candidate;

            $text = file_get_contents($absolutePath);
            if (!is_string($text) || $text === '') {
                break;
            }

            $tool = self::staticAnalysisToolFromText($text);
            if ($tool !== null) {
                $hasStaticAnalysisIntegration = true;
                $evidence[] = $candidate . ' sets staticAnalysisTool=' . $tool;
            }

            break;
        }

        if ($usesInfection && !$hasStaticAnalysisIntegration) {
            $evidence[] = 'missing staticAnalysisTool=phpstan|mago in Infection config or command';
        }

        $evidence = array_values(array_unique($evidence));
        sort($evidence, SORT_STRING);

        return [
            'usesInfection' => $usesInfection,
            'hasStaticAnalysisIntegration' => $hasStaticAnalysisIntegration,
            'configPath' => $configPath,
            'locationPath' => $locationPath,
            'evidence' => $evidence,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function readJsonFile(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $composer */
    private static function composerUsesInfection(array $composer): bool
    {
        foreach (['require', 'require-dev'] as $section) {
            $dependencies = $composer[$section] ?? null;
            if (is_array($dependencies) && array_key_exists('infection/infection', $dependencies)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $composer
     * @return array<string,list<string>>
     */
    private static function composerScriptCommands(array $composer): array
    {
        $scripts = $composer['scripts'] ?? null;
        if (!is_array($scripts)) {
            return [];
        }

        $commands = [];
        foreach ($scripts as $name => $script) {
            if (!is_string($name)) {
                continue;
            }

            $normalized = self::normalizeScriptCommands($script);
            if ($normalized === []) {
                continue;
            }

            $commands[$name] = $normalized;
        }

        ksort($commands, SORT_STRING);

        return $commands;
    }

    /** @return list<string> */
    private static function normalizeScriptCommands(mixed $script): array
    {
        if (is_string($script)) {
            return [$script];
        }

        if (!is_array($script)) {
            return [];
        }

        $commands = [];
        foreach ($script as $command) {
            if (is_string($command)) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    private static function mentionsInfection(string $text): bool
    {
        return preg_match('/(^|[^a-z])infection([^a-z]|$)/i', $text) === 1;
    }

    private static function staticAnalysisToolFromText(string $text): ?string
    {
        if (preg_match('/--static-analysis-tool(?:=|\s+)(phpstan|mago)\b/i', $text, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (preg_match('/\bstaticAnalysisTool\b\s*:\s*["\']?(phpstan|mago)["\']?/i', $text, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
