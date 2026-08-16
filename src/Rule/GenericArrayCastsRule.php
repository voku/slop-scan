<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class GenericArrayCastsRule extends BaseRule
{
    private const DEFAULT_MAX_FILE_LINES = 5000;

    public function id(): string
    {
        return 'php.generic-array-casts';
    }

    public function family(): string
    {
        return 'type-safety';
    }

    public function scope(): string
    {
        return 'file';
    }

    public function requires(): array
    {
        return ['file.genericArrayCasts', 'file.logicalLineCount'];
    }

    public function evaluate(ProviderContext $context): array
    {
        $maxFileLines = max(
            1,
            (int) ($context->ruleConfig['options']['maxFileLines'] ?? self::DEFAULT_MAX_FILE_LINES),
        );
        $logicalLineCount = (int) $context->runtime->store->getFileFact(
            $context->file->path,
            'file.logicalLineCount',
        );
        if ($logicalLineCount > $maxFileLines) {
            return [];
        }

        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.genericArrayCasts') ?? [] as $match) {
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found generic array conversion assigned to a vague bag variable',
                [
                    'variable=' . $match['variable'],
                    'kind=' . $match['kind'],
                ],
                2.0,
                [[
                    'path' => $context->file->path,
                    'line' => $match['line'],
                    'column' => $match['column'],
                ]],
                $context->file->path,
            );
        }

        return $findings;
    }
}
