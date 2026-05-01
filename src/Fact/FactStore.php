<?php

declare(strict_types=1);

namespace SlopScan\Fact;
final class FactStore
{
    /** @var array<string,mixed> */
    private array $repoFacts = [];
    /** @var array<string,array<string,mixed>> */
    private array $directoryFacts = [];
    /** @var array<string,array<string,mixed>> */
    private array $fileFacts = [];

    public function getRepoFact(string $factId): mixed
    {
        return $this->repoFacts[$factId] ?? null;
    }

    public function setRepoFact(string $factId, mixed $value): void
    {
        $this->repoFacts[$factId] = $value;
    }

    public function getDirectoryFact(string $directoryPath, string $factId): mixed
    {
        return $this->directoryFacts[$directoryPath][$factId] ?? null;
    }

    public function setDirectoryFact(string $directoryPath, string $factId, mixed $value): void
    {
        $this->directoryFacts[$directoryPath][$factId] = $value;
    }

    public function getFileFact(string $filePath, string $factId): mixed
    {
        return $this->fileFacts[$filePath][$factId] ?? null;
    }

    public function setFileFact(string $filePath, string $factId, mixed $value): void
    {
        $this->fileFacts[$filePath][$factId] = $value;
    }

    /** @param array<string,mixed> $facts */
    public function setFileFacts(string $filePath, array $facts): void
    {
        $this->fileFacts[$filePath] = array_replace($this->fileFacts[$filePath] ?? [], $facts);
    }

    /** @return list<string> */
    public function listFilePathsWithFact(string $factId): array
    {
        $paths = [];
        foreach ($this->fileFacts as $path => $facts) {
            if (array_key_exists($factId, $facts)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}
