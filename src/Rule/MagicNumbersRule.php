<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class MagicNumbersRule extends BaseRule
{
    private const DEFAULT_IGNORED_VALUES = [0, 1];

    public function id(): string { return 'php.magic-numbers'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        $ignoredValues = $this->ignoredValues($context);

        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            foreach ($function['magicNumbers'] ?? [] as $number) {
                if (in_array($number['normalized'] ?? null, $ignoredValues, true)) {
                    continue;
                }

                $findings[] = new Finding(
                    $this->id(),
                    $this->family(),
                    $this->severity(),
                    'file',
                    'Found inline PHP magic number literal',
                    [
                        $function['name'],
                        'value=' . $number['value'],
                        'kind=' . $number['kind'],
                    ],
                    0.5,
                    [[
                        'path' => $context->file->path,
                        'line' => (int) ($number['line'] ?? 1),
                        'column' => (int) ($number['column'] ?? 1),
                    ]],
                    $context->file->path
                );
            }
        }

        return $findings;
    }

    /** @return list<string> */
    private function ignoredValues(ProviderContext $context): array
    {
        $configured = $context->ruleConfig['options']['ignoredValues'] ?? self::DEFAULT_IGNORED_VALUES;
        if (!is_array($configured)) {
            $configured = self::DEFAULT_IGNORED_VALUES;
        }

        $normalized = [];
        foreach ($configured as $value) {
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                continue;
            }
            if (!is_numeric((string) $value)) {
                continue;
            }

            $normalized[] = json_encode(0 + $value, JSON_THROW_ON_ERROR);
        }

        return array_values(array_unique($normalized));
    }
}
