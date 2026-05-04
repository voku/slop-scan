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
            if ($this->sharesNamespace($locations) || $this->sharesParentDirectoryPrefix($locations, 3)) {
                continue;
            }

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

    /** @param list<array{name:string,path:string,line:int,namespaceName?:?string}> $locations */
    private function sharesNamespace(array $locations): bool
    {
        $namespaces = array_values(array_unique(array_filter(
            array_map(static fn(array $location): ?string => is_string($location['namespaceName'] ?? null) && $location['namespaceName'] !== '' ? $location['namespaceName'] : null, $locations)
        )));

        return $namespaces !== [] && count($namespaces) === 1;
    }

    /** @param list<array{name:string,path:string,line:int,namespaceName?:?string}> $locations */
    private function sharesParentDirectoryPrefix(array $locations, int $depth): bool
    {
        $prefixes = array_values(array_unique(array_map(
            static fn(array $location): string => self::directoryPrefix((string) $location['path'], $depth),
            $locations
        )));

        return $prefixes !== [] && count($prefixes) === 1;
    }

    private static function directoryPrefix(string $path, int $depth): string
    {
        $directory = trim(str_replace('\\', '/', dirname($path)), '/');
        if ($directory === '' || $directory === '.') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $directory), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '';
        }

        return implode('/', array_slice($segments, 0, $depth));
    }
}
