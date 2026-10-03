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

    public function testConditionalPhpUnitSkipDoesNotDisableWholeTest(): void
    {
        $before = $this->phpUnitTest('$this->assertSame(120, Invoice::make(100)->total());');
        $after = $this->phpUnitTest(<<<'PHP'
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('intl is required');
        }

        $this->assertSame(120, Invoice::make(100)->total());
PHP);

        self::assertSame([], $this->compare($before, $after));
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

    public function testRenameWithUnchangedBodyDoesNotLookDeleted(): void
    {
        $body = '$this->assertSame(120, Invoice::make(100)->total());';
        $before = $this->phpUnitTest($body, 'testTotals');
        $after = $this->phpUnitTest($body, 'testTotalsIncludeTax');

        self::assertSame([], $this->compare($before, $after));
    }

    public function testUnrelatedAddedTestWithSameAssertionCountDoesNotReplaceDeletedTest(): void
    {
        $before = $this->phpUnitTest(
            '$this->assertSame(120, Invoice::make(100)->total());',
            'testTotals',
        );
        $after = $this->phpUnitTest(
            '$this->assertSame("ok", Exporter::run());',
            'testCsvExport',
        );

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertStringContainsString('testTotals()', $findings[0]->message);
        self::assertStringContainsString('was deleted', $findings[0]->message);
    }

    public function testForeignAssertMethodIsNotCountedAsPhpUnitAssertion(): void
    {
        $before = $this->phpUnitTest(<<<'PHP'
        $service->assertReady();
        $this->assertSame(120, Invoice::make(100)->total());
PHP);
        $after = $this->phpUnitTest(<<<'PHP'
        $service->checkReady();
        $this->assertSame(120, Invoice::make(100)->total());
PHP);

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

    public function testConditionalPestSkipFalseDoesNotDisableTest(): void
    {
        $before = <<<'PHP'
<?php
it('adds tax', function (): void {
    expect(Invoice::make(100)->total())->toBe(120);
});
PHP;
        $after = <<<'PHP'
<?php
it('adds tax', function (): void {
    expect(Invoice::make(100)->total())->toBe(120);
})->skip(false);
PHP;

        self::assertSame([], $this->compare($before, $after));
    }

    public function testReorderedNamedPestSkipFalseDoesNotDisableTest(): void
    {
        $before = <<<'PHP'
<?php
it('adds tax', function (): void {
    expect(Invoice::make(100)->total())->toBe(120);
});
PHP;
        $after = <<<'PHP'
<?php
it('adds tax', function (): void {
    expect(Invoice::make(100)->total())->toBe(120);
})->skip(message: 'requires intl', conditionOrMessage: false);
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

    public function testCodeceptionCestTracksActorAssertionsAndIgnoresLifecycleMethods(): void
    {
        $path = 'Acceptance/CheckoutCest.php';
        $before = <<<'PHP'
<?php
final class CheckoutCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->amOnPage('/checkout');
        $I->see('setup marker');
    }

    public function checkout(AcceptanceTester $I): void
    {
        $I->amOnPage('/checkout');
        $I->see('Checkout');
        $I->dontSee('Payment failed');
    }
}
PHP;
        $after = <<<'PHP'
<?php
final class CheckoutCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->amOnPage('/checkout');
        $I->see('setup marker');
    }

    public function checkout(AcceptanceTester $I): void
    {
        $I->amOnPage('/checkout');
        $I->see('Checkout');
    }
}
PHP;

        $inventory = TestInventory::fromSource($before, $path);
        self::assertNotNull($inventory);
        self::assertArrayHasKey('CheckoutCest::checkout', $inventory);
        self::assertArrayNotHasKey('CheckoutCest::_before', $inventory);
        self::assertSame(2, $inventory['CheckoutCest::checkout']->assertions);

        $findings = $this->compare($before, $after, $path);

        self::assertCount(1, $findings);
        self::assertStringContainsString('CheckoutCest::checkout()', $findings[0]->message);
        self::assertStringContainsString('down from 2', $findings[0]->message);
    }

    public function testCodeceptionActorAssertPrefixRemainsAssertionEvidence(): void
    {
        $path = 'Acceptance/CheckoutCest.php';
        $before = <<<'PHP'
<?php
final class CheckoutCest
{
    public function checkout(AcceptanceTester $I): void
    {
        $I->assertEquals('Checkout', Page::title());
        $I->see('Checkout');
    }
}
PHP;
        $after = <<<'PHP'
<?php
final class CheckoutCest
{
    public function checkout(AcceptanceTester $I): void
    {
        $I->assertEquals('Checkout', Page::title());
    }
}
PHP;

        $inventory = TestInventory::fromSource($before, $path);
        self::assertNotNull($inventory);
        self::assertSame(2, $inventory['CheckoutCest::checkout']->assertions);

        $findings = $this->compare($before, $after, $path);

        self::assertCount(1, $findings);
        self::assertStringContainsString('down from 2', $findings[0]->message);
    }

    public function testCodeceptionCestTracksNewSkipAttribute(): void
    {
        $path = 'Acceptance/CheckoutCest.php';
        $before = <<<'PHP'
<?php
final class CheckoutCest
{
    public function checkout(AcceptanceTester $I): void
    {
        $I->see('Checkout');
    }
}
PHP;
        $after = <<<'PHP'
<?php
final class CheckoutCest
{
    #[Skip('temporarily disabled')]
    public function checkout(AcceptanceTester $I): void
    {
        $I->see('Checkout');
    }
}
PHP;

        $findings = $this->compare($before, $after, $path);

        self::assertCount(1, $findings);
        self::assertSame('high', $findings[0]->confidence);
        self::assertStringContainsString('is now skipped', $findings[0]->message);
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
    private function compare(
        ?string $before,
        string $after,
        string $path = 'tests/Feature/InvoiceTest.php',
    ): array {
        $beforeInventory = $before === null ? [] : TestInventory::fromSource($before, $path);
        $afterInventory = TestInventory::fromSource($after, $path);

        self::assertNotNull($beforeInventory);
        self::assertNotNull($afterInventory);

        return WeakenedTests::compareInventories($path, $beforeInventory, $afterInventory);
    }

    public function testDeletedTestFileIsReported(): void
    {
        $base = sys_get_temp_dir() . '/slop-scan-weakened-base-' . bin2hex(random_bytes(4));
        $head = sys_get_temp_dir() . '/slop-scan-weakened-head-' . bin2hex(random_bytes(4));
        mkdir($base . '/tests/Feature', 0777, true);
        mkdir($head . '/tests/Feature', 0777, true);

        file_put_contents(
            $base . '/tests/Feature/InvoiceTest.php',
            $this->phpUnitTest('$this->assertSame(120, Invoice::make(100)->total());'),
        );

        try {
            $findings = WeakenedTests::comparePaths($base, $head);

            self::assertCount(1, $findings);
            self::assertSame('php.weakened-tests', $findings[0]->ruleId);
            self::assertStringContainsString('testTotals()', $findings[0]->message);
            self::assertStringContainsString('was deleted', $findings[0]->message);
        } finally {
            $this->remove($base);
            $this->remove($head);
        }
    }

    public function testTestMovedUnchangedToAnotherFileIsNotReportedAsDeleted(): void
    {
        $base = sys_get_temp_dir() . '/slop-scan-weakened-base-' . bin2hex(random_bytes(4));
        $head = sys_get_temp_dir() . '/slop-scan-weakened-head-' . bin2hex(random_bytes(4));
        mkdir($base . '/tests', 0777, true);
        mkdir($head . '/tests', 0777, true);

        $body = '$this->assertSame(120, Invoice::make(100)->total());';
        file_put_contents($base . '/tests/InvoiceTest.php', $this->phpUnitTest($body));
        file_put_contents(
            $head . '/tests/InvoiceTotalsTest.php',
            str_replace('InvoiceTest', 'InvoiceTotalsTest', $this->phpUnitTest($body)),
        );

        try {
            self::assertSame([], WeakenedTests::comparePaths($base, $head));
        } finally {
            $this->remove($base);
            $this->remove($head);
        }
    }

    public function testForeignAssertMethodExercisesCodeBeforeAssertionCount(): void
    {
        $after = $this->phpUnitTest(<<<'PHP'
        $service->assertReady();
        $this->addToAssertionCount(1);
PHP);

        self::assertSame([], $this->compare(null, $after));
    }

    public function testNoExceptionSmokeTestWithAssertionCountIsNotTrivial(): void
    {
        $after = $this->phpUnitTest("Invoice::make(1)->total();\n\$this->addToAssertionCount(1);");

        self::assertSame([], $this->compare(null, $after));
    }

    public function testNewTestWithMeaningfulAndTrivialAssertionIsNotReported(): void
    {
        $after = $this->phpUnitTest(<<<'PHP'
        $this->assertSame(120, Invoice::make(100)->total());
        $this->assertTrue(true);
PHP);

        self::assertSame([], $this->compare(null, $after));
    }

    public function testMovedTestThatBecomesSkippedIsStillReported(): void
    {
        $base = sys_get_temp_dir() . '/slop-scan-weakened-base-' . bin2hex(random_bytes(4));
        $head = sys_get_temp_dir() . '/slop-scan-weakened-head-' . bin2hex(random_bytes(4));
        mkdir($base . '/Acceptance', 0777, true);
        mkdir($head . '/Moved', 0777, true);

        $before = <<<'PHP'
<?php
final class CheckoutCest
{
    public function checkout(AcceptanceTester $I): void
    {
        $I->see('Checkout');
    }
}
PHP;
        $after = <<<'PHP'
<?php
final class CheckoutCest
{
    #[Skip('temporarily disabled')]
    public function checkout(AcceptanceTester $I): void
    {
        $I->see('Checkout');
    }
}
PHP;

        file_put_contents($base . '/Acceptance/CheckoutCest.php', $before);
        file_put_contents($head . '/Moved/CheckoutCest.php', $after);

        try {
            $findings = WeakenedTests::comparePaths($base, $head);

            self::assertCount(1, $findings);
            self::assertStringContainsString('CheckoutCest::checkout()', $findings[0]->message);
            self::assertStringContainsString('was deleted', $findings[0]->message);
        } finally {
            $this->remove($base);
            $this->remove($head);
        }
    }

    public function testRenamedAndRewrittenTestWithMoreAssertionsIsNotReportedAsDeleted(): void
    {
        $before = $this->phpUnitTests([
            'testCloseRefusesWithholding' => '$this->assertSame(1, Close::run()->status());',
        ]);
        $after = $this->phpUnitTests([
            'testCloseRefusesWithholdingFromAnUnselectedEvent' => '$this->assertSame(1, Close::run()->status());' . "\n" . '$this->assertSame("refused", Close::run()->reason());',
        ]);

        self::assertSame([], $this->compare($before, $after));
    }

    public function testOneTestSplitIntoRelatedTestsIsNotReportedAsDeleted(): void
    {
        $before = $this->phpUnitTests([
            'testCheckMode' => '$this->assertTrue(Config::check("a"));' . "\n" . '$this->assertTrue(Config::check("b"));',
        ]);
        $after = $this->phpUnitTests([
            'testCheckModeDefaultConfig' => '$this->assertSame("a", Config::check("a"));',
            'testCheckModeWhenAddingConfig' => '$this->assertSame("b", Config::check("b"));',
        ]);

        self::assertSame([], $this->compare($before, $after));
    }

    public function testRewriteWithFewerTotalAssertionsStillReportsTheDeletedTest(): void
    {
        $before = $this->phpUnitTests([
            'testHandoffIsRoutedThroughWorkflowCli' => '$this->assertSame(1, Cli::run());' . "\n" . '$this->assertSame(2, Cli::runner());' . "\n" . '$this->assertSame(3, Cli::shared());',
        ]);
        $after = $this->phpUnitTests([
            'testHandoffIsRoutedThroughRecallOwner' => '$this->assertSame(1, Cli::run());',
        ]);

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertStringContainsString('was deleted', $findings[0]->message);
        self::assertContains('file-removed-assertions=3', $findings[0]->evidence);
        self::assertContains('file-added-assertions=1', $findings[0]->evidence);
    }

    public function testOneSharedGenericNameWordDoesNotMakeAnUnrelatedTestAReplacement(): void
    {
        $before = $this->phpUnitTests(['testGetLexer' => '$this->assertNotNull(Parser::make()->getLexer());']);
        $after = $this->phpUnitTests(['testGetTokens' => '$this->assertNotNull(Parser::make()->getTokens());']);

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertStringContainsString('testGetLexer()', $findings[0]->message);
    }

    public function testRenameThatKeepsTheWholeShorterNameIsRelated(): void
    {
        $before = $this->phpUnitTest('$this->assertSame(120, Invoice::make(100)->total());', 'testTotals');
        $after = $this->phpUnitTest('$this->assertSame(120, Invoice::make(100)->withTax()->total());', 'testTotalsIncludeTax');

        self::assertSame([], $this->compare($before, $after));
    }

    public function testFewerAddedTestsThanRemovedStillReportsDeletion(): void
    {
        $before = $this->phpUnitTests([
            'testExportCsvHeader' => '$this->assertSame("id", Export::csv()->header());',
            'testExportCsvRows' => '$this->assertCount(2, Export::csv()->rows());',
        ]);
        $after = $this->phpUnitTests([
            'testExportCsvHeaderAndRows' => '$this->assertSame("id", Export::csv()->header());' . "\n" . '$this->assertCount(2, Export::csv()->rows());',
        ]);

        self::assertCount(2, $this->compare($before, $after));
    }

    public function testSkippedReplacementProvidesNoEvidence(): void
    {
        $before = $this->phpUnitTests(['testCloseRefusesWithholding' => '$this->assertSame(1, Close::run()->status());']);
        $after = $this->phpUnitTests([
            'testCloseRefusesWithholdingFromAnUnselectedEvent' => '$this->markTestSkipped("later");' . "\n" . '$this->assertSame(1, Close::run()->status());',
        ]);

        $findings = $this->compare($before, $after);

        self::assertCount(1, $findings);
        self::assertStringContainsString('was deleted', $findings[0]->message);
    }

    /** @param array<string,string> $tests */
    private function phpUnitTests(array $tests): string
    {
        $methods = '';
        foreach ($tests as $name => $body) {
            $methods .= "    public function {$name}(): void\n    {\n{$body}\n    }\n\n";
        }

        return "<?php\nfinal class InvoiceTest extends TestCase\n{\n" . $methods . "}\n";
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

    private function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->remove($path . DIRECTORY_SEPARATOR . $item);
        }

        rmdir($path);
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
