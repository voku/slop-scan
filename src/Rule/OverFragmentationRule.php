<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class OverFragmentationRule extends BaseRule
{
    public function id(): string { return 'php.over-fragmentation'; }
    public function family(): string { return 'structure'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'directory'; }
    public function requires(): array { return ['directory.record']; }

    public function evaluate(ProviderContext $context): array
    {
        $small = 0;
        foreach ($context->directory->filePaths as $path) {
            $lineCount = $context->runtime->store->getFileFact($path, 'file.logicalLineCount') ?? 0;
            $functionCount = count($context->runtime->store->getFileFact($path, 'file.functionSummaries') ?? []);
            if ($lineCount > 0 && $lineCount <= 12 && $functionCount <= 1) {
                $small++;
            }
        }
        if ($small < 6 || $small < count($context->directory->filePaths) / 2) {
            return [];
        }
        return [new Finding($this->id(), $this->family(), $this->severity(), 'directory', 'Found many tiny PHP files in one directory', ["tinyFiles={$small}"], 1.0, [['path' => $context->directory->filePaths[0], 'line' => 1, 'column' => 1]], $context->directory->path)];
    }
}
