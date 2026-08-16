<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Model\AnalysisResult;
use SlopScan\Model\Finding;

final class ReviewRegressionTest extends TestCase
{
    public function testDuplicateMockSetupIgnoresUnrelatedFluentApisAcrossThreeTestFiles(): void
    {
        $result = $this->analyzeFiles([
            'tests/AlphaTest.php' => <<<'PHP'
<?php
use PHPUnit\Framework\TestCase;
final class AlphaTest extends TestCase
{
    public function testAlpha(): void
    {
        $query = new QueryBuilder();
        $query->method('active')->willReturn('id');
        self::assertTrue(true);
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
        $query = new QueryBuilder();
        $query->method('published')->willReturn('slug');
        self::assertTrue(true);
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
        $query = new QueryBuilder();
        $query->method('visible')->willReturn('name');
        self::assertTrue(true);
    }
}
PHP,
        ]);

        self::assertSame([], $this->forRule($result->findings, 'php.duplicate-mock-setup'));
    }

    public function testMisleadingPhpDocTypesAllowsLocalPhpstanTypeAlias(): void
    {
        $result = $this->analyzeFiles([
            'src/AliasSummary.php' => <<<'PHP'
<?php

/**
 * @phpstan-type Summary array{items:list<string>}
 */
final class AliasSummary
{
    /** @return Summary */
    public static function summarize(): array
    {
        return ['items' => []];
    }
}
PHP,
        ]);

        self::assertSame([], $this->forRule($result->findings, 'php.misleading-phpdoc-types'));
    }

    /** @param array<string,string> $files */
    private function analyzeFiles(array $files): AnalysisResult
    {
        $fixture = sys_get_temp_dir() . '/slop-scan-review-regression-' . bin2hex(random_bytes(4));

        foreach ($files as $path => $contents) {
            $absolutePath = $fixture . '/' . $path;
            $directory = dirname($absolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($absolutePath, $contents);
        }

        try {
            return (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());
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
