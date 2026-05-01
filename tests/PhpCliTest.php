<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Delta;
use SlopScan\DirectoryRecord;
use SlopScan\FactStore;
use SlopScan\FileRecord;
use SlopScan\Finding;
use SlopScan\Json;
use SlopScan\JsonReporter;
use SlopScan\LintReporter;
use SlopScan\Lines;
use SlopScan\PatternMatcher;
use SlopScan\PhpFacts;
use SlopScan\PhpLanguage;
use SlopScan\ProviderContext;
use SlopScan\Registry;
use SlopScan\Scheduler;
use SlopScan\TextReporter;

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
        self::assertSame(['php.empty-catch', 'php.pass-through-wrappers', 'php.placeholder-comments'], $this->ruleIds($result->findings));
        self::assertSame($this->fixtureDir, $result->rootDir);
        self::assertSame('src/Example.php', $result->files[0]->path);
        self::assertSame('src', $result->directories[0]->path);
        self::assertSame(['src/Example.php'], $result->directories[0]->filePaths);
        self::assertSame('src/Example.php', $result->fileScores[0]['path']);
    }

    public function testJsonReporterKeepsReportShape(): void
    {
        $result = (new Analyzer())->analyze($this->fixtureDir, Config::load($this->fixtureDir), DefaultRegistry::create());
        $decoded = json_decode((new JsonReporter())->render($result), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('findings', $decoded);
        self::assertSame('slop-scan-php', $decoded['metadata']['tool']['name']);
        self::assertSame(1, $decoded['metadata']['schemaVersion']);
        self::assertSame('src/Example.php', $decoded['files'][0]['path']);
        self::assertArrayHasKey('deltaIdentity', $decoded['findings'][0]);
    }

    public function testConfigIgnoresAndRuleWeightsAreApplied(): void
    {
        file_put_contents($this->fixtureDir . '/slop-scan.config.json', Json::encode([
            'ignores' => ['src/Ignore.php'],
            'rules' => [
                'php.empty-catch' => ['enabled' => false],
                'php.placeholder-comments' => ['weight' => 2.0],
            ],
            'thresholds' => ['unused' => 1],
            'overrides' => [['path' => 'src/Example.php']],
        ]));
        file_put_contents($this->fixtureDir . '/src/Ignore.php', "<?php\n// TODO ignored\n");

        $config = Config::load($this->fixtureDir);
        $result = (new Analyzer())->analyze($this->fixtureDir, $config, DefaultRegistry::create());

        self::assertSame(['src/Ignore.php'], $config['ignores']);
        self::assertSame(1, $config['thresholds']['unused']);
        self::assertSame([['path' => 'src/Example.php']], $config['overrides']);
        self::assertSame(['php.pass-through-wrappers', 'php.placeholder-comments'], $this->ruleIds($result->findings));
        self::assertSame(1.0, $result->findings[0]->score);
    }

    public function testAnalyzerFindsDirectoryAndRepoRegressions(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/many', 0777, true);
        for ($i = 1; $i <= 13; $i++) {
            file_put_contents($fixture . "/many/Tiny{$i}.php", "<?php\nfunction tiny{$i}() {\n}\n");
        }
        mkdir($fixture . '/dupes', 0777, true);
        file_put_contents($fixture . '/dupes/A.php', "<?php\nfunction copied(\$value) {\n    return \$value;\n}\n");
        file_put_contents($fixture . '/dupes/B.php', "<?php\nfunction copied(\$other) {\n    return \$other;\n}\n");
        file_put_contents($fixture . '/dupes/Wrapper.php', "<?php\nfunction wrapper(\$value) {\n    return transform(\$value);\n}\ntry {\n    risky();\n} catch (Throwable \$e) {\n    error_log(\$e->getMessage());\n}\n");

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame([
            'php.directory-fanout-hotspot',
            'php.duplicate-function-signatures',
            'php.error-swallowing',
            'php.over-fragmentation',
            'php.pass-through-wrappers',
        ], $this->ruleIds($result->findings));
        self::assertSame('many', $result->directoryScores[0]['path']);
        self::assertSame(16, $result->summary['fileCount']);
        self::assertSame(16, $result->summary['functionCount']);
        self::assertNotNull($result->summary['normalized']['scorePerKloc']);
    }

    public function testClassConstructorsDoNotCreateDuplicateFunctionSignatureFindings(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/First.php', "<?php\nclass First {\n    public function __construct(string \$name) {}\n}\n");
        file_put_contents($fixture . '/src/Second.php', "<?php\nclass Second {\n    public function __construct(string \$name) {}\n}\n");

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertNotContains('php.duplicate-function-signatures', $this->ruleIds($result->findings));
    }

    public function testReportersRenderEmptyTextLintAndJson(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/clean', 0777, true);
        file_put_contents($fixture . '/clean/Ok.php', "<?php\nfunction ok(\$value) {\n    return \$value + 1;\n}\n");
        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame('0 findings', (new LintReporter())->render($result));
        self::assertStringContainsString('slop-scan report', (new TextReporter())->render($result));
        self::assertStringContainsString('"findingCount": 0', (new JsonReporter())->render($result));
    }

    public function testDeltaReportsAddedResolvedAndFailStatus(): void
    {
        $finding = new Finding('rule.one', 'family', 'medium', 'file', 'message', ['evidence'], 1.0, [['path' => 'a.php', 'line' => 1]], 'a.php');
        $base = ['findings' => [$finding->toReport()]];
        $headFinding = new Finding('rule.two', 'family', 'medium', 'file', 'message', ['evidence'], 1.0, [['path' => 'b.php', 'line' => 2]], 'b.php');
        $head = ['findings' => [$headFinding->toReport()]];

        $delta = Delta::diff($base, $head);

        self::assertSame(1, $delta['summary']['added']);
        self::assertSame(1, $delta['summary']['resolved']);
        $statuses = array_column($delta['changes'], 'status');
        sort($statuses, SORT_STRING);
        self::assertSame(['added', 'resolved'], $statuses);
    }

    public function testCliScanDeltaAndHelp(): void
    {
        $base = $this->makeFixture();
        $head = $this->makeFixture();
        mkdir($base . '/src', 0777, true);
        mkdir($head . '/src', 0777, true);
        file_put_contents($base . '/src/A.php', "<?php\nfunction clean_base() { return 1; }\n");
        file_put_contents($head . '/src/A.php', "<?php\n// TODO added\nfunction clean_head() { return 1; }\n");

        [$scanExit, $scanOutput] = $this->runCommand(['scan', $head, '--json']);
        $scan = json_decode($scanOutput, true, 512, JSON_THROW_ON_ERROR);
        [$deltaExit, $deltaOutput] = $this->runCommand(['delta', '--base', $base, '--head', $head, '--fail-on', 'added']);
        [$helpExit, $helpOutput] = $this->runCommand(['--help']);
        [$unknownExit] = $this->runCommand(['unknown']);

        self::assertSame(0, $scanExit);
        self::assertSame(1, $scan['summary']['findingCount']);
        self::assertSame(1, $deltaExit);
        self::assertStringContainsString('added: 1', $deltaOutput);
        self::assertSame(0, $helpExit);
        self::assertStringContainsString('slop-scan', $helpOutput);
        self::assertSame(1, $unknownExit);
    }

    public function testCliDeltaReadsReportFilesAndMissingValuesFail(): void
    {
        $baseReport = $this->fixtureDir . '/base.json';
        $headReport = $this->fixtureDir . '/head.json';
        file_put_contents($baseReport, Json::encode(['findings' => []]));
        $finding = new Finding('rule.added', 'family', 'medium', 'file', 'message', [], 1.0, [['path' => 'new.php', 'line' => 1]], 'new.php');
        file_put_contents($headReport, Json::encode(['findings' => [$finding->toReport()]]));

        [$exit, $output] = $this->runCommand(['delta', '--base-report', $baseReport, '--head-report', $headReport, '--json']);
        [$missingExit] = $this->runCommand(['scan', '--ignore']);

        self::assertSame(0, $exit);
        self::assertSame(1, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['summary']['added']);
        self::assertSame(1, $missingExit);
    }

    public function testPatternMatchingLanguageRegistryAndSchedulerEdges(): void
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerReporter(new JsonReporter());

        self::assertTrue(PatternMatcher::matches('src/Foo.php', 'src/*.php'));
        self::assertTrue(PatternMatcher::ignored('vendor/pkg/Foo.php', ['**/vendor/**']));
        self::assertFalse(PatternMatcher::ignored('src/Foo.php', ['**/vendor/**']));
        self::assertSame('php', $registry->detectLanguage('template.phtml')?->id());
        self::assertNull($registry->detectLanguage('README.md'));
        self::assertSame('json', $registry->reporter('json')->id());

        $this->expectException(\RuntimeException::class);
        $registry->reporter('missing');
    }

    public function testSchedulerDetectsUnresolvedProviderDependencies(): void
    {
        $provider = new class implements \SlopScan\FactProvider {
            public function id(): string { return 'blocked'; }
            public function scope(): string { return 'repo'; }
            public function requires(): array { return ['missing.fact']; }
            public function provides(): array { return ['next.fact']; }
            public function supports(ProviderContext $context): bool { return true; }
            public function run(ProviderContext $context): array { return []; }
        };

        $this->expectException(\RuntimeException::class);
        Scheduler::orderFactProviders([$provider]);
    }

    public function testFactsAndLowLevelModels(): void
    {
        $php = <<<'PHP'
<?php
// TODO comment
class Box {
    public function value($input = null) {
        return wrap($input);
    }
}
function value($input = null) {
    try {
        risky();
    } catch (Throwable $e) {
    }
}
PHP;
        $file = $this->fixtureDir . '/src/Facts.php';
        file_put_contents($file, $php);
        $functions = PhpFacts::functions($php);
        $catches = PhpFacts::tryCatches($php);
        $summary = PhpFacts::parserSummary($file);
        $store = new FactStore();
        $record = new FileRecord('src/Facts.php', $file, '.php', Lines::physical($php), Lines::logical($php), 'php');
        $directory = new DirectoryRecord('src', ['src/Facts.php']);

        $store->setRepoFact('repo.fact', true);
        $store->setDirectoryFact('src', 'directory.fact', 2);
        $store->setFileFacts('src/Facts.php', ['one' => 1]);
        $store->setFileFact('src/Facts.php', 'two', 2);

        self::assertSame(0, Lines::physical(''));
        self::assertSame(2, Lines::physical("a\nb"));
        self::assertSame(13, $record->lineCount);
        self::assertSame(['src/Facts.php'], $directory->toReport()['filePaths']);
        self::assertTrue($store->getRepoFact('repo.fact'));
        self::assertSame(2, $store->getDirectoryFact('src', 'directory.fact'));
        self::assertSame(2, $store->getFileFact('src/Facts.php', 'two'));
        self::assertSame(['src/Facts.php'], $store->listFilePathsWithFact('one'));
        self::assertSame(['// TODO comment'], array_column(PhpFacts::comments($php), 'text'));
        self::assertSame(['box::value(1)', 'value(1)'], array_column($functions, 'signature'));
        self::assertSame(11, $catches[0]['line']);
        self::assertArrayHasKey('available', $summary);
    }

    /** @return list<string> */
    private function ruleIds(array $findings): array
    {
        $ids = array_values(array_unique(array_map(static fn(Finding $finding): string => $finding->ruleId, $findings)));
        sort($ids, SORT_STRING);
        return $ids;
    }

    private function makeFixture(): string
    {
        $path = sys_get_temp_dir() . '/slop-scan-php-' . bin2hex(random_bytes(4));
        mkdir($path, 0777, true);
        return $path;
    }

    /** @param list<string> $arguments @return array{0:int,1:string} */
    private function runCommand(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/slop-scan.php'], $arguments);
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(array_map('strval', $command), $descriptor, $pipes, dirname(__DIR__));
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [$exit, (string) $output];
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
