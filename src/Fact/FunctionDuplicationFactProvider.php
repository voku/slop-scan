<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use SlopScan\Contract\FactProvider;
use SlopScan\Runtime\ProviderContext;

final class FunctionDuplicationFactProvider implements FactProvider
{
    public function id(): string { return 'repo.functionDuplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.files', 'file.functionSummaries']; }
    public function provides(): array { return ['repo.duplicateFunctionSignatures']; }
    public function supports(ProviderContext $context): bool { return true; }

    public function run(ProviderContext $context): array
    {
        $groups = [];
        foreach ($context->runtime->files as $file) {
            foreach ($context->runtime->store->getFileFact($file->path, 'file.functionSummaries') ?? [] as $function) {
                $groups[$function['signature']][] = ['path' => $file->path, 'line' => $function['line'], 'name' => $function['name']];
            }
        }
        $duplicates = array_filter($groups, static fn(array $group): bool => count($group) > 1);
        ksort($duplicates, SORT_STRING);
        return ['repo.duplicateFunctionSignatures' => $duplicates];
    }
}
