<?php

declare(strict_types=1);

namespace SlopScan;

final class DuplicateFunctionSignaturesRule extends BaseRule
{
    public function id(): string { return 'php.duplicate-function-signatures'; }
    public function family(): string { return 'duplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.duplicateFunctionSignatures']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getRepoFact('repo.duplicateFunctionSignatures') ?? [] as $signature => $locations) {
            $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'repo', 'Found duplicated PHP function signatures', [$signature], 2.0, array_map(static fn(array $location): array => ['path' => $location['path'], 'line' => $location['line'], 'column' => 1], $locations), null);
        }
        return $findings;
    }
}
