<?php

declare(strict_types=1);

namespace SlopScan\Contract;

use SlopScan\Runtime\ProviderContext;

interface FactProvider
{
    public function id(): string;
    public function scope(): string;
    /** @return list<string> */
    public function requires(): array;
    /** @return list<string> */
    public function provides(): array;
    public function supports(ProviderContext $context): bool;
    /** @return array<string,mixed> */
    public function run(ProviderContext $context): array;
}
