<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\DefaultRegistry;
use SlopScan\Support\FindingMetadataCatalog;

final class FindingMetadataCatalogTest extends TestCase
{
    private const ALLOWED_CONFIDENCE = ['high', 'medium', 'low'];

    /**
     * Registry and catalog are joined only at read time, and the read path returns nulls for a
     * miss rather than failing, so a rule can ship with no agent-facing metadata and nothing
     * reports it. This asserts the two stay in step.
     */
    public function testEveryRegisteredRuleHasAgentFacingMetadata(): void
    {
        $missing = [];
        foreach (DefaultRegistry::create()->rules() as $rule) {
            if (FindingMetadataCatalog::forRule($rule->id())['why'] === null) {
                $missing[] = $rule->id();
            }
        }
        sort($missing, SORT_STRING);

        self::assertSame(
            [],
            $missing,
            'Rules registered without a FindingMetadataCatalog entry: ' . implode(', ', $missing),
        );
    }

    public function testRegisteredRuleMetadataIsUsable(): void
    {
        foreach (DefaultRegistry::create()->rules() as $rule) {
            $metadata = FindingMetadataCatalog::forRule($rule->id());

            self::assertNotSame('', trim((string) $metadata['why']), $rule->id() . ' has an empty why');
            self::assertNotSame('', trim((string) $metadata['suggestedAction']), $rule->id() . ' has an empty suggestedAction');
            self::assertContains(
                $metadata['confidence'],
                self::ALLOWED_CONFIDENCE,
                $rule->id() . ' has confidence "' . (string) $metadata['confidence'] . '" outside the allowed vocabulary',
            );
        }
    }
}
