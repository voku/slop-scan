<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class TypeEscapeHotspotsRule extends BaseRule
{
    private const DEFAULT_THRESHOLD = 3;

    public function id(): string { return 'php.type-escape-hotspots'; }
    public function family(): string { return 'type-safety'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.typeEscapeSummary']; }

    public function evaluate(ProviderContext $context): array
    {
        $summary = $context->runtime->store->getFileFact($context->file->path, 'file.typeEscapeSummary');
        if ($summary === null) {
            return [];
        }

        $threshold = (int) ($context->ruleConfig['options']['threshold'] ?? self::DEFAULT_THRESHOLD);
        $mixedCount = (int) ($summary['mixedTypeCount'] ?? 0);
        $castCount = (int) ($summary['castCount'] ?? 0);
        $total = $mixedCount + $castCount;

        if ($mixedCount === 0 || $total < $threshold) {
            return [];
        }

        return [new Finding(
            $this->id(),
            $this->family(),
            $this->severity(),
            'file',
            'Found file with concentrated type escape patterns',
            ['mixed-types=' . $mixedCount, 'casts=' . $castCount, 'total=' . $total],
            (float) $total,
            [['path' => $context->file->path, 'line' => 1, 'column' => 1]],
            $context->file->path
        )];
    }
}
