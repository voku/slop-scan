<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class MockHeavyTestsWithoutAssertionsRule extends BaseRule
{
    private const DEFAULT_MOCK_THRESHOLD = 2;
    private const MOCK_HEAVY_TEST_SCORE = 1.25;

    public function id(): string { return 'php.mock-heavy-tests-without-assertions'; }
    public function family(): string { return 'tests'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.testCallSummary']; }

    public function evaluate(ProviderContext $context): array
    {
        $summary = $context->runtime->store->getFileFact($context->file->path, 'file.testCallSummary') ?? null;
        if (!is_array($summary) || !($summary['looksLikeTest'] ?? false)) {
            return [];
        }

        $testCount = (int) ($summary['testCount'] ?? 0);
        if ($testCount === 0) {
            return [];
        }

        $mockCount = (int) ($summary['mockCount'] ?? 0);
        $assertionCount = (int) ($summary['assertionCount'] ?? 0);
        $expectationCount = (int) ($summary['expectationCount'] ?? 0);
        $threshold = max(1, (int) ($context->ruleConfig['options']['mockCount'] ?? self::DEFAULT_MOCK_THRESHOLD));
        if ($mockCount < $threshold || $assertionCount > 0 || $expectationCount > 0) {
            return [];
        }

        return [new Finding(
            $this->id(),
            $this->family(),
            $this->severity(),
            'file',
            'Found mock-heavy PHP test file without assertions or mock expectations',
            [
                'tests=' . $testCount,
                'mocks=' . $mockCount,
                'assertions=' . $assertionCount,
                'expectations=' . $expectationCount,
            ],
            self::MOCK_HEAVY_TEST_SCORE,
            [['path' => $context->file->path, 'line' => 1, 'column' => 1]],
            $context->file->path
        )];
    }
}
