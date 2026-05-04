<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ExceptionWrapWithoutPreviousRule extends BaseRule
{
    public function id(): string { return 'php.exception-wrap-without-previous'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            foreach ($catch['thrownExceptions'] ?? [] as $thrownException) {
                if (($thrownException['isGeneric'] ?? false)
                    || ($thrownException['preservesPrevious'] ?? false)
                    || !($thrownException['usesCaughtVariable'] ?? false)
                ) {
                    continue;
                }

                $findings[] = new Finding(
                    $this->id(),
                    $this->family(),
                    $this->severity(),
                    'file',
                    'Found PHP catch block that wraps an exception without preserving previous context',
                    [
                        'class=' . ($thrownException['class'] ?? 'unknown'),
                        'reason=wraps-caught-exception-without-previous',
                    ],
                    2.0,
                    [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]],
                    $context->file->path
                );
            }
        }

        return $findings;
    }
}
