<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Model\AnalysisResult;
use SlopScan\Model\Finding;

final class DuplicateMockSetupRuleTest extends TestCase
{
    public function testFlagsSameMockSetupShapeAcrossThreeTestFiles(): void
    {
        $result = $this->analyzeFiles([
            'tests/ClockServiceTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class ClockServiceTest extends TestCase
{
    public function testFormatsCurrentTime(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(1710000000);

        self::assertSame('12:00', format_time($clock));
    }
}
PHP,
            'tests/OrderServiceTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    public function testUsesOrderTimestamp(): void
    {
        $timeSource = $this->createMock(TimeSource::class);
        $timeSource->method('timestamp')->willReturn(1720000000);

        self::assertSame(1720000000, order_timestamp($timeSource));
    }
}
PHP,
            'tests/TokenServiceTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    public function testUsesIssuedAt(): void
    {
        $provider = $this->createMock(DateProvider::class);
        $provider->method('issuedAt')->willReturn(1730000000);

        self::assertSame(1730000000, token_issued_at($provider));
    }
}
PHP,
        ]);

        $findings = $this->forRule($result->findings, 'php.duplicate-mock-setup');
        $paths = array_map(static fn (Finding $finding): ?string => $finding->path, $findings);
        sort($paths, SORT_STRING);

        self::assertSame(
            [
                'tests/ClockServiceTest.php',
                'tests/OrderServiceTest.php',
                'tests/TokenServiceTest.php',
            ],
            $paths,
        );
        foreach ($findings as $finding) {
            self::assertCount(3, $finding->locations);
        }
    }

    public function testFlagsEquivalentGetMockBuilderChainsAcrossThreeTestFiles(): void
    {
        $result = $this->analyzeFiles([
            'tests/AlphaTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class AlphaTest extends TestCase
{
    public function testAlpha(): void
    {
        $alpha = $this->getMockBuilder(AlphaGateway::class)->disableOriginalConstructor()->getMock();
        self::assertInstanceOf(AlphaGateway::class, $alpha);
    }
}
PHP,
            'tests/BetaTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class BetaTest extends TestCase
{
    public function testBeta(): void
    {
        $beta = $this->getMockBuilder(BetaGateway::class)->disableOriginalConstructor()->getMock();
        self::assertInstanceOf(BetaGateway::class, $beta);
    }
}
PHP,
            'tests/GammaTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class GammaTest extends TestCase
{
    public function testGamma(): void
    {
        $gamma = $this->getMockBuilder(GammaGateway::class)->disableOriginalConstructor()->getMock();
        self::assertInstanceOf(GammaGateway::class, $gamma);
    }
}
PHP,
        ]);

        $findings = $this->forRule($result->findings, 'php.duplicate-mock-setup');
        self::assertCount(3, $findings);
        foreach ($findings as $finding) {
            self::assertSame(['setup=getMockBuilder|getMock|files=3'], $finding->evidence);
            self::assertCount(3, $finding->locations);
        }
    }

    public function testNeedsThreeConfiguredFilesAndIgnoresBareMockDeclarations(): void
    {
        $result = $this->analyzeFiles([
            'tests/FirstServiceTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class FirstServiceTest extends TestCase
{
    public function testFirst(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(1);

        self::assertSame(1, read_clock($clock));
    }
}
PHP,
            'tests/SecondServiceTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class SecondServiceTest extends TestCase
{
    public function testSecond(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(2);

        self::assertSame(2, read_clock($clock));
    }
}
PHP,
            'tests/BareMockTest.php' => <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class BareMockTest extends TestCase
{
    public function testBareMockIsNotSetupDuplication(): void
    {
        $clock = $this->createMock(Clock::class);

        self::assertInstanceOf(Clock::class, $clock);
    }
}
PHP,
        ]);

        self::assertSame([], $this->forRule($result->findings, 'php.duplicate-mock-setup'));
    }

    public function testRepeatedSetupInOneFileDoesNotSatisfyThreeFileThreshold(): void
    {
        $result = $this->analyzeFiles([
            'tests/FirstServiceTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class FirstServiceTest extends TestCase
{
    public function testFirst(): void
    {
        $first = $this->createMock(Clock::class);
        $first->method('now')->willReturn(1);
        $second = $this->createMock(Clock::class);
        $second->method('later')->willReturn(2);
        self::assertTrue(true);
    }
}
PHP,
            'tests/SecondServiceTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class SecondServiceTest extends TestCase
{
    public function testSecond(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(3);
        self::assertTrue(true);
    }
}
PHP,
        ]);

        self::assertSame([], $this->forRule($result->findings, 'php.duplicate-mock-setup'));
    }

    /**
     * @param array<string, string> $files
     */
    private function analyzeFiles(array $files): AnalysisResult
    {
        $fixture = sys_get_temp_dir() . '/slop-scan-duplicate-mock-' . bin2hex(random_bytes(4));

        foreach ($files as $path => $contents) {
            $absolutePath = $fixture . '/' . $path;
            $directory = dirname($absolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($absolutePath, $contents);
        }

        try {
            return (new Analyzer())->analyze(
                $fixture,
                Config::defaults(),
                DefaultRegistry::create(),
            );
        } finally {
            $this->remove($fixture);
        }
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function forRule(array $findings, string $ruleId): array
    {
        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === $ruleId,
        ));
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
}
