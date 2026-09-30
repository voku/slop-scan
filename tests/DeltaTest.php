<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Delta;
use SlopScan\Model\Finding;

final class DeltaTest extends TestCase
{
    public function testUniqueSemanticFindingCanMoveWithoutBecomingNew(): void
    {
        $delta = Delta::diff(
            ['findings' => [$this->finding(10)]],
            ['findings' => [$this->finding(16)]],
        );

        self::assertSame(['added' => 0, 'resolved' => 0], $delta['summary']);
        self::assertSame([], $delta['changes']);
    }

    public function testChangedEvidenceDoesNotMasqueradeAsRelocation(): void
    {
        $delta = Delta::diff(
            ['findings' => [$this->finding(10, 'return=null')]],
            ['findings' => [$this->finding(16, 'return=false')]],
        );

        self::assertSame(['added' => 1, 'resolved' => 1], $delta['summary']);
        self::assertCount(2, $delta['changes']);
    }

    public function testAmbiguousDuplicateRelocationsRemainExactMatchOnly(): void
    {
        $delta = Delta::diff(
            ['findings' => [$this->finding(10), $this->finding(20)]],
            ['findings' => [$this->finding(16), $this->finding(26)]],
        );

        self::assertSame(['added' => 2, 'resolved' => 2], $delta['summary']);
        self::assertCount(4, $delta['changes']);
    }

    public function testAdditionalFindingsJoinDeltaAsAddedChanges(): void
    {
        $finding = new Finding(
            ruleId: 'php.weakened-tests',
            family: 'tests',
            severity: 'medium',
            scope: 'file',
            message: 'InvoiceTest::testTotals() makes 1 assertion, down from 3',
            evidence: ['test=InvoiceTest::testTotals', 'kind=assertions'],
            score: 2.0,
            locations: [['path' => 'tests/InvoiceTest.php', 'line' => 10, 'column' => 1]],
            path: 'tests/InvoiceTest.php',
            deltaIdentity: [
                'fingerprintVersion' => 1,
                'occurrences' => [[
                    'fingerprint' => 'semantic-test-fingerprint',
                    'path' => 'tests/InvoiceTest.php',
                    'line' => 10,
                    'column' => 1,
                ]],
            ],
        );

        $delta = Delta::withAddedFindings(
            ['summary' => ['added' => 0, 'resolved' => 0], 'changes' => []],
            [$finding],
        );

        self::assertSame(['added' => 1, 'resolved' => 0], $delta['summary']);
        self::assertSame('added', $delta['changes'][0]['status']);
        self::assertSame('semantic-test-fingerprint', $delta['changes'][0]['fingerprint']);
        self::assertSame('php.weakened-tests', $delta['changes'][0]['finding']['ruleId']);
    }

    /** @return array<string, mixed> */
    private function finding(int $line, string $evidence = 'return=null'): array
    {
        return (new Finding(
            ruleId: 'php.catch-default-fallbacks',
            family: 'error-handling',
            severity: 'medium',
            scope: 'file',
            message: 'Found PHP catch block that returns a default fallback literal',
            evidence: [$evidence],
            score: 2,
            locations: [[
                'path' => 'src/Workflow/WorkflowContextCommand.php',
                'line' => $line,
                'column' => 1,
            ]],
            path: 'src/Workflow/WorkflowContextCommand.php',
        ))->toReport();
    }
}
