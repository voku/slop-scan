<?php

declare(strict_types=1);

namespace SlopScan;

final class Analyzer
{
    public function analyze(string $rootDir, array $config, Registry $registry): AnalysisResult
    {
        $root = realpath($rootDir) ?: $rootDir;
        $discovery = Discoverer::discover($root, $config, $registry);
        $store = new FactStore();
        $runtime = new AnalyzerRuntime($root, $config, $discovery['files'], $discovery['directories'], $store);
        $store->setRepoFact('repo.files', $discovery['files']);
        $store->setRepoFact('repo.directories', $discovery['directories']);
        foreach ($discovery['directories'] as $directory) {
            $store->setDirectoryFact($directory->path, 'directory.record', $directory);
        }

        $providers = Scheduler::orderFactProviders($registry->factProviders(), ['file.record', 'file.text', 'directory.record', 'repo.files', 'repo.directories']);
        $findings = [];
        foreach ($discovery['files'] as $file) {
            $text = (string) file_get_contents($file->absolutePath);
            $file->lineCount = Lines::physical($text);
            $file->logicalLineCount = Lines::logical($text);
            $context = new ProviderContext('file', $runtime, $file);
            $store->setFileFacts($file->path, ['file.record' => $file, 'file.text' => $text, 'file.lineCount' => $file->lineCount, 'file.logicalLineCount' => $file->logicalLineCount]);
            $this->runProviders($providers, $context, $store);
            $findings = array_merge($findings, $this->runRules($registry->rules(), $context, $config));
        }
        foreach ($discovery['directories'] as $directory) {
            $context = new ProviderContext('directory', $runtime, null, $directory);
            $this->runProviders($providers, $context, $store);
            $findings = array_merge($findings, $this->runRules($registry->rules(), $context, $config));
        }
        $repoContext = new ProviderContext('repo', $runtime);
        $this->runProviders($providers, $repoContext, $store);
        $findings = array_merge($findings, $this->runRules($registry->rules(), $repoContext, $config));
        usort($findings, static fn(Finding $left, Finding $right): int => strcmp($left->ruleId . ($left->path ?? ''), $right->ruleId . ($right->path ?? '')));

        return $this->result($root, $config, $discovery['files'], $discovery['directories'], $findings, $store);
    }

    /** @param list<FactProvider> $providers */
    private function runProviders(array $providers, ProviderContext $context, FactStore $store): void
    {
        foreach ($providers as $provider) {
            if ($provider->scope() !== $context->scope || !$provider->supports($context)) {
                continue;
            }
            foreach ($provider->run($context) as $fact => $value) {
                if ($context->scope === 'file' && $context->file !== null) {
                    $store->setFileFact($context->file->path, $fact, $value);
                } elseif ($context->scope === 'directory' && $context->directory !== null) {
                    $store->setDirectoryFact($context->directory->path, $fact, $value);
                } else {
                    $store->setRepoFact($fact, $value);
                }
            }
        }
    }

    /** @param list<RulePlugin> $rules @param array<string,mixed> $config @return list<Finding> */
    private function runRules(array $rules, ProviderContext $context, array $config): array
    {
        $findings = [];
        foreach ($rules as $rule) {
            if ($rule->scope() !== $context->scope) {
                continue;
            }
            $ruleConfig = $this->ruleConfig($config, $rule->id());
            $context->ruleConfig = $ruleConfig;
            if (!$ruleConfig['enabled'] || !$rule->supports($context)) {
                continue;
            }
            foreach ($rule->evaluate($context) as $finding) {
                $finding->score *= (float) $ruleConfig['weight'];
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /** @param array<string,mixed> $config @return array{enabled:bool,weight:float,options:mixed} */
    private function ruleConfig(array $config, string $ruleId): array
    {
        $rule = is_array($config['rules'][$ruleId] ?? null) ? $config['rules'][$ruleId] : [];
        return ['enabled' => (bool) ($rule['enabled'] ?? true), 'weight' => (float) ($rule['weight'] ?? 1.0), 'options' => $rule['options'] ?? null];
    }

    /** @param list<FileRecord> $files @param list<DirectoryRecord> $directories @param list<Finding> $findings */
    private function result(string $root, array $config, array $files, array $directories, array $findings, FactStore $store): AnalysisResult
    {
        $repoScore = array_reduce($findings, static fn(float $sum, Finding $finding): float => $sum + $finding->score, 0.0);
        $physical = array_reduce($files, static fn(int $sum, FileRecord $file): int => $sum + $file->lineCount, 0);
        $logical = array_reduce($files, static fn(int $sum, FileRecord $file): int => $sum + $file->logicalLineCount, 0);
        $functionCount = 0;
        foreach ($files as $file) {
            $functionCount += count($store->getFileFact($file->path, 'file.functionSummaries') ?? []);
        }
        $kloc = $logical / 1000;
        $summary = [
            'fileCount' => count($files),
            'directoryCount' => count($directories),
            'findingCount' => count($findings),
            'repoScore' => $repoScore,
            'physicalLineCount' => $physical,
            'logicalLineCount' => $logical,
            'functionCount' => $functionCount,
            'normalized' => [
                'scorePerFile' => self::divide($repoScore, count($files)),
                'scorePerKloc' => self::divide($repoScore, $kloc),
                'scorePerFunction' => self::divide($repoScore, $functionCount),
                'findingsPerFile' => self::divide(count($findings), count($files)),
                'findingsPerKloc' => self::divide(count($findings), $kloc),
                'findingsPerFunction' => self::divide(count($findings), $functionCount),
            ],
        ];
        return new AnalysisResult($root, $config, $summary, $files, $directories, $findings, $this->fileScores($files, $findings), $this->directoryScores($directories, $findings), $repoScore);
    }

    private static function divide(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @param list<FileRecord> $files @param list<Finding> $findings @return list<array{path:string,score:float,findingCount:int}> */
    private function fileScores(array $files, array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            if ($finding->path === null) {
                continue;
            }
            $scores[$finding->path] ??= ['path' => $finding->path, 'score' => 0.0, 'findingCount' => 0];
            $scores[$finding->path]['score'] += $finding->score;
            $scores[$finding->path]['findingCount']++;
        }
        usort($scores, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: strcmp($left['path'], $right['path']));
        return array_values($scores);
    }

    /** @param list<DirectoryRecord> $directories @param list<Finding> $findings @return list<array{path:string,score:float,findingCount:int}> */
    private function directoryScores(array $directories, array $findings): array
    {
        $scores = [];
        foreach ($findings as $finding) {
            if ($finding->scope !== 'directory' || $finding->path === null) {
                continue;
            }
            $scores[$finding->path] ??= ['path' => $finding->path, 'score' => 0.0, 'findingCount' => 0];
            $scores[$finding->path]['score'] += $finding->score;
            $scores[$finding->path]['findingCount']++;
        }
        usort($scores, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: strcmp($left['path'], $right['path']));
        return array_values($scores);
    }
}
