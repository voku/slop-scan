<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class DirectoryFanoutHotspotRule extends BaseRule
{
    public function id(): string { return 'php.directory-fanout-hotspot'; }
    public function family(): string { return 'structure'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.metrics']; }

    public function evaluate(ProviderContext $context): array
    {
        $metrics = $context->runtime->store->getDirectoryFact($context->directory->path, 'directory.metrics') ?? [];
        $threshold = (int) ($context->ruleConfig['options']['fileCount'] ?? 12);
        if (($metrics['fileCount'] ?? 0) <= $threshold) {
            return [];
        }
        return [new Finding($this->id(), $this->family(), $this->severity(), 'directory', 'Found high PHP file fanout in one directory', ['fileCount=' . $metrics['fileCount']], 1.5, [['path' => $context->directory->filePaths[0] ?? $context->directory->path, 'line' => 1, 'column' => 1]], $context->directory->path)];
    }
}
