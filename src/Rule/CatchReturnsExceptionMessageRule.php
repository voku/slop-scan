<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class CatchReturnsExceptionMessageRule extends BaseRule
{
    private const DEFAULT_MAX_FILE_LINES = 5000;

    public function id(): string { return 'php.catch-returns-exception-message'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array
    {
        return [
            'file.tryCatches',
            'file.caughtExceptionNormalizations',
            'file.logicalLineCount',
        ];
    }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            $returnedCaughtValueKinds = $catch['returnedCaughtValueKinds'] ?? [];
            if ($returnedCaughtValueKinds === []) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP catch block that returns the caught exception as data',
                array_map(static fn(string $kind): string => 'return=' . $kind, $returnedCaughtValueKinds),
                2.0,
                [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]],
                $context->file->path
            );
        }

        $maxFileLines = max(
            1,
            (int) ($context->ruleConfig['options']['maxFileLines'] ?? self::DEFAULT_MAX_FILE_LINES),
        );
        $logicalLineCount = (int) $context->runtime->store->getFileFact(
            $context->file->path,
            'file.logicalLineCount',
        );
        if ($logicalLineCount > $maxFileLines) {
            return $findings;
        }

        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.caughtExceptionNormalizations') ?? [] as $normalization) {
            $kind = (string) ($normalization['kind'] ?? '');
            if (str_starts_with($kind, 'returned-')) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP catch block that flattens the caught exception into generic string data',
                ['normalization=' . $kind],
                2.0,
                [[
                    'path' => $context->file->path,
                    'line' => $normalization['line'],
                    'column' => $normalization['column'],
                ]],
                $context->file->path,
            );
        }

        return $findings;
    }
}
