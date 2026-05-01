<?php

declare(strict_types=1);

namespace SlopScan;

final class EmptyCatchRule extends BaseRule
{
    public function id(): string { return 'php.empty-catch'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            if (trim($catch['body']) === '') {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found empty PHP catch block', ['catch block has no statements'], 2.0, [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}
