<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ErrorObscuringCatchRule extends BaseRule
{
    public function id(): string { return 'php.error-obscuring-catch'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            foreach ($catch['thrownExceptions'] ?? [] as $thrownException) {
                if (!($thrownException['isGeneric'] ?? false) || ($thrownException['preservesPrevious'] ?? false)) {
                    continue;
                }

                $findings[] = new Finding(
                    $this->id(),
                    $this->family(),
                    $this->severity(),
                    'file',
                    'Found PHP catch block that replaces the original error with a generic exception',
                    [
                        'class=' . ($thrownException['class'] ?? 'unknown'),
                        'reason=generic-replacement-without-previous',
                    ],
                    2.25,
                    [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]],
                    $context->file->path
                );
            }
        }

        return $findings;
    }
}
