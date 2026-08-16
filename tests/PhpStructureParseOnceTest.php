<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Fact\PhpFacts;

final class PhpStructureParseOnceTest extends TestCase
{
    public function testUncachedPhpStructureFactsShareOneRawAstParse(): void
    {
        $fixture = sys_get_temp_dir() . '/slop-scan-parse-once-' . bin2hex(random_bytes(4));
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Example.php', <<<'PHP'
<?php

final class Example
{
    public function run(string $value): string
    {
        try {
            var_dump($value);
            return transform($value);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
PHP);

        $parserCalls = 0;
        PhpFacts::useParserFactoryForTesting(static function () use (&$parserCalls): Parser {
            $parserCalls++;

            return (new ParserFactory())->createForHostVersion();
        });

        try {
            (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

            self::assertSame(1, $parserCalls, 'php.structure should parse one uncached PHP source once for raw AST-derived facts.');
        } finally {
            PhpFacts::useParserFactoryForTesting(null);
            $this->remove($fixture);
        }
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
