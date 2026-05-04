<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class CatchDefaultFallbacksRule extends BaseRule
{
    public function id(): string { return 'php.catch-default-fallbacks'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            $defaultReturnKinds = $catch['defaultReturnKinds'] ?? [];
            if ($defaultReturnKinds === []) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP catch block that returns a default fallback literal',
                array_map(static fn(string $kind): string => 'return=' . $kind, $defaultReturnKinds),
                2.0,
                [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }
}
