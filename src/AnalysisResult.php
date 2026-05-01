<?php

declare(strict_types=1);

namespace SlopScan;

final class AnalysisResult
{
    /**
     * @param list<FileRecord> $files
     * @param list<DirectoryRecord> $directories
     * @param list<Finding> $findings
     * @param array<string,mixed> $config
     * @param array<string,mixed> $summary
     * @param list<array{path:string,score:float,findingCount:int}> $fileScores
     * @param list<array{path:string,score:float,findingCount:int}> $directoryScores
     */
    public function __construct(
        public string $rootDir,
        public array $config,
        public array $summary,
        public array $files,
        public array $directories,
        public array $findings,
        public array $fileScores,
        public array $directoryScores,
        public float $repoScore,
    ) {
    }

    /** @return array<string,mixed> */
    public function toReport(): array
    {
        return [
            'metadata' => [
                'schemaVersion' => 1,
                'tool' => ['name' => 'slop-scan-php', 'version' => '0.1.0'],
                'configHash' => hash('sha256', Json::encode($this->config)),
                'findingFingerprintVersion' => 1,
                'plugins' => [],
            ],
            'rootDir' => $this->rootDir,
            'config' => $this->config,
            'summary' => $this->summary,
            'files' => array_map(static fn(FileRecord $file): array => $file->toReport(), $this->files),
            'directories' => array_map(static fn(DirectoryRecord $directory): array => $directory->toReport(), $this->directories),
            'findings' => array_map(static fn(Finding $finding): array => $finding->toReport(), $this->findings),
            'fileScores' => $this->fileScores,
            'directoryScores' => $this->directoryScores,
        ];
    }
}
