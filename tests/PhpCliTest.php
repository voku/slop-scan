<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\JsonReporter;
use SlopScan\LintReporter;

final class PhpCliTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/slop-scan-php-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureDir . '/src', 0777, true);
        file_put_contents($this->fixtureDir . '/src/Example.php', <<<'PHP'
<?php

// TODO: replace placeholder
function proxy($value) {
    return transform($value);
}

try {
    risky();
} catch (Throwable $e) {
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->fixtureDir);
    }

    public function testScanProducesPhpFindings(): void
    {
        $result = (new Analyzer())->analyze($this->fixtureDir, Config::load($this->fixtureDir), DefaultRegistry::create());

        self::assertSame(1, $result->summary['fileCount']);
        self::assertGreaterThanOrEqual(2, $result->summary['findingCount']);
        self::assertStringContainsString('php.empty-catch', (new LintReporter())->render($result));
    }

    public function testJsonReporterKeepsReportShape(): void
    {
        $result = (new Analyzer())->analyze($this->fixtureDir, Config::load($this->fixtureDir), DefaultRegistry::create());
        $decoded = json_decode((new JsonReporter())->render($result), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('findings', $decoded);
        self::assertSame('slop-scan-php', $decoded['metadata']['tool']['name']);
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
