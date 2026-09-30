<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Model\Finding;
use SlopScan\Review\TestInventory;
use SlopScan\Review\WeakenedTests;

final class WeakenedTestsTest extends TestCase
{
    public function testFlagsExistingPhpUnitTestThatLostAssertions(): void
    {
        $findings = $this->compare(
            $this->phpUnitTest(<<<'PHP'
        $invoice = Invoice::make(100);
        $this->assertSame(100, $invoice->net());
        $this->assertSame(20, $invoice->tax());
        $this->assertSame(120, $invoice->total());
PHP),
            $this->phpUnitTest(<<<'PHP'
        $invoice = Invoice::make(100);
        $this->assertSame(100, $invoice->net());
PHP),
        );

        self::assertCount(1, $findings);
        self::assertSame('php.weakened-tests', $findings[0]->ruleId);
        self::assertSame('medium', $findings[0]->severity);
        self::assertSame('medium', $findings[0]->confidence);
        self::assertStringContainsString('down from 3', $findings[0]->message);
    }

    public function testFlagsNewSkipBeforeAssertionLoss(): void
    {
        $before = $this->phpUnitTest('$this->assertSame(120, Invoice::make(100)->total());');
        $after = $this->phpUnitTest(<<<'PHP'
        $this->markTestSkipped('flaky');
        $this->assertSame(120, Invoice::make(100)->total());
PHP);

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertSame('high', $findings[0]->confidence);
        self::assertStringContainsString('is now skipped', $findings[0]->message);
    }

    public function testFlagsTrivialAssertionInNewPestTestAtWeakSeverity(): void
    {
        $after = <<<'PHP'
<?php
it('exports invoices', function (): void {
    Exporter::run();
    expect(true)->toBeTrue();
});
PHP;

        $findings = $this->compare(null, $after);

        self::assertCount(1, $findings);
        self::assertSame('weak', $findings[0]->severity);
        self::assertSame('medium', $findings[0]->confidence);
        self::assertStringContainsString('cannot fail', $findings[0]->message);
    }

    public function testRenameWithComparableAssertionsDoesNotLookDeleted(): void
    {
        $before = $this->phpUnitTest(
            '$this->assertSame(120, Invoice::make(100)->total());',
            'testTotals',
        );
        $after = $this->phpUnitTest(
            '$this->assertSame(121, Invoice::make(101)->total());',
            'testTotalsIncludeTax',
        );

        self::assertSame([], $this->compare($before, $after));
    }

    public function testAssertionsMovedIntoLocalHelperAreStillCounted(): void
    {
        $before = $this->phpUnitTest(<<<'PHP'
        $invoice = Invoice::make(100);
        $this->assertSame(100, $invoice->net());
        $this->assertSame(20, $invoice->tax());
        $this->assertSame(120, $invoice->total());
PHP);

        $after = <<<'PHP'
<?php
final class InvoiceTest extends TestCase
{
    public function testTotals(): void
    {
        $this->assertTotals(Invoice::make(100));
    }

    private function assertTotals(Invoice $invoice): void
    {
        $this->assertSame(100, $invoice->net());
        $this->assertSame(20, $invoice->tax());
        $this->assertSame(120, $invoice->total());
    }
}
PHP;

        self::assertSame([], $this->compare($before, $after));
    }

    public function testPestDescribeTracksAssertionLossAndSkip(): void
    {
        $before = <<<'PHP'
<?php
describe('totals', function (): void {
    it('adds tax', function (): void {
        expect(Invoice::make(100)->total())->toBe(120);
        expect(Invoice::make(0)->total())->toBe(0);
    });

    it('rounds', function (): void {
        expect(Invoice::make(99)->tax())->toBe(20);
    });
});
PHP;
        $after = <<<'PHP'
<?php
describe('totals', function (): void {
    it('adds tax', function (): void {
        expect(Invoice::make(100)->total())->toBe(120);
    });

    it('rounds', function (): void {
        expect(Invoice::make(99)->tax())->toBe(20);
    })->skip();
});
PHP;

        $messages = array_map(
            static fn (Finding $finding): string => $finding->message,
            $this->compare($before, $after),
        );

        self::assertCount(2, $messages);
        self::assertTrue($this->contains($messages, 'totals > it adds tax'));
        self::assertTrue($this->contains($messages, 'totals > it rounds'));
    }

    public function testDeletedTestWithoutReplacementIsReported(): void
    {
        $before = <<<'PHP'
<?php
final class InvoiceTest extends TestCase
{
    public function testTotals(): void
    {
        $this->assertSame(120, Invoice::make(100)->total());
    }

    public function testDiscount(): void
    {
        $this->assertSame(90, Invoice::make(100)->discounted(10));
    }
}
PHP;
        $after = $this->phpUnitTest(
            '$this->assertSame(120, Invoice::make(100)->total());',
            'testTotals',
        );

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertSame('weak', $findings[0]->severity);
        self::assertStringContainsString('testDiscount()', $findings[0]->message);
    }

    /**
     * @return list<\SlopScan\Model\Finding>
     */
    private function compare(?string $before, string $after): array
    {
        $path = 'tests/Feature/InvoiceTest.php';
        $beforeInventory = $before === null ? [] : TestInventory::fromSource($before, $path);
        $afterInventory = TestInventory::fromSource($after, $path);

        self::assertNotNull($beforeInventory);
        self::assertNotNull($afterInventory);

        return WeakenedTests::compareInventories($path, $beforeInventory, $afterInventory);
    }

    private function phpUnitTest(string $body, string $name = 'testTotals'): string
    {
        return <<<PHP
<?php
final class InvoiceTest extends TestCase
{
    public function {$name}(): void
    {
{$body}
    }
}
PHP;
    }

    /** @param list<string> $messages */
    private function contains(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
