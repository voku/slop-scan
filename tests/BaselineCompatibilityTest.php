<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\BaselineCompatibility;
use SlopScan\Config;

final class BaselineCompatibilityTest extends TestCase
{
    public function testMetadataUsesOnlyEffectiveActiveRules(): void
    {
        $config = Config::defaults();
        $config['rules']['php.debug-output'] = ['enabled' => false];

        $metadata = BaselineCompatibility::metadata(
            $config,
            [
                'rules' => ['php.empty-catch', 'php.debug-output'],
                'paths' => [],
                'maxFindings' => null,
                'minScore' => null,
            ],
        );

        self::assertSame(BaselineCompatibility::RULE_SEMANTICS_VERSION, $metadata['ruleSemanticsVersion']);
        self::assertSame(['php.empty-catch'], $metadata['activeRuleIds']);
    }

    public function testMatchingCompatibilityMetadataAllowsBaselineComparison(): void
    {
        $metadata = [
            'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION,
            'activeRuleIds' => ['php.empty-catch'],
        ];

        BaselineCompatibility::assertCompatible($this->report($metadata), $this->report($metadata));

        self::addToAssertionCount(1);
    }

    public function testLegacyBaselineWithoutCompatibilityMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Baseline compatibility metadata is missing');

        BaselineCompatibility::assertCompatible(
            ['metadata' => [], 'findings' => []],
            $this->report([
                'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION,
                'activeRuleIds' => ['php.empty-catch'],
            ]),
        );
    }

    public function testChangedRuleSurfaceFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('added=[php.generic-array-casts]');

        BaselineCompatibility::assertCompatible(
            $this->report([
                'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION,
                'activeRuleIds' => ['php.empty-catch'],
            ]),
            $this->report([
                'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION,
                'activeRuleIds' => ['php.empty-catch', 'php.generic-array-casts'],
            ]),
        );
    }

    public function testChangedRuleSemanticsVersionFailsClosedWithSameRuleIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Baseline rule semantics are incompatible');

        BaselineCompatibility::assertCompatible(
            $this->report([
                'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION,
                'activeRuleIds' => ['php.empty-catch'],
            ]),
            $this->report([
                'ruleSemanticsVersion' => BaselineCompatibility::RULE_SEMANTICS_VERSION + 1,
                'activeRuleIds' => ['php.empty-catch'],
            ]),
        );
    }

    /**
     * @param array{ruleSemanticsVersion:int,activeRuleIds:list<string>} $compatibility
     * @return array<string, mixed>
     */
    private function report(array $compatibility): array
    {
        return [
            'metadata' => [
                'baselineCompatibility' => $compatibility,
            ],
            'findings' => [],
        ];
    }
}
