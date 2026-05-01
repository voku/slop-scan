<?php

declare(strict_types=1);

namespace SlopScan;

final class Scheduler
{
    /** @param list<FactProvider> $providers @param list<string> $initialFacts @return list<FactProvider> */
    public static function orderFactProviders(array $providers, array $initialFacts = []): array
    {
        $ordered = [];
        $available = array_fill_keys($initialFacts, true);
        while ($providers !== []) {
            $readyIndex = null;
            foreach ($providers as $index => $provider) {
                if (array_reduce($provider->requires(), static fn(bool $carry, string $fact): bool => $carry && isset($available[$fact]), true)) {
                    $readyIndex = $index;
                    break;
                }
            }
            if ($readyIndex === null) {
                throw new \RuntimeException('Unresolved fact provider dependencies.');
            }
            $provider = array_splice($providers, $readyIndex, 1)[0];
            $ordered[] = $provider;
            foreach ($provider->provides() as $fact) {
                $available[$fact] = true;
            }
        }
        return $ordered;
    }
}
