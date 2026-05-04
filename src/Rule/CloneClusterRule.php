<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class CloneClusterRule extends BaseRule
{
    public function id(): string { return 'php.clone-cluster'; }
    public function family(): string { return 'duplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.cloneFunctionBodies']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getRepoFact('repo.cloneFunctionBodies') ?? [] as $locations) {
            $names = array_values(array_unique(array_column($locations, 'name')));
            sort($names, SORT_STRING);

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'repo',
                'Found near-duplicate PHP function bodies',
                array_map(static fn(string $name): string => 'name=' . $name, $names),
                2.0,
                array_map(static fn(array $loc): array => ['path' => $loc['path'], 'line' => $loc['line'], 'column' => 1], $locations),
                null
            );
        }

        return $findings;
    }
}
