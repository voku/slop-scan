<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use SlopScan\Contract\FactProvider;
use SlopScan\Runtime\ProviderContext;

final class DirectoryMetricsFactProvider implements FactProvider
{
    public function id(): string { return 'directory.metrics'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.record']; }
    public function provides(): array { return ['directory.metrics']; }
    public function supports(ProviderContext $context): bool { return $context->directory !== null; }

    public function run(ProviderContext $context): array
    {
        return ['directory.metrics' => ['fileCount' => count($context->directory->filePaths)]];
    }
}
