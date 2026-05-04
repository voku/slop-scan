<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use SlopScan\Analyzer;
use SlopScan\Baseline;
use SlopScan\Config;
use SlopScan\Console\CommandSupport;
use SlopScan\Console\DeltaCommand;
use SlopScan\Console\ScanCommand;
use SlopScan\Console\SlopScanApplication;
use SlopScan\Contract\FactProvider;
use SlopScan\DefaultRegistry;
use SlopScan\Delta;
use SlopScan\Discoverer;
use SlopScan\Fact\FactStore;
use SlopScan\Fact\PhpFacts;
use SlopScan\Model\AnalysisResult;
use SlopScan\Model\DirectoryRecord;
use SlopScan\Model\FileRecord;
use SlopScan\Model\Finding;
use SlopScan\PhpLanguage;
use SlopScan\Registry;
use SlopScan\Reporter\GithubReporter;
use SlopScan\Reporter\JsonReporter;
use SlopScan\Reporter\LintReporter;
use SlopScan\Reporter\TextReporter;
use SlopScan\Runtime\ProviderContext;
use SlopScan\Scheduler;
use SlopScan\Support\Json;
use SlopScan\Support\Lines;
use SlopScan\Support\PatternMatcher;
use SlopScan\Support\ScanCache;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class PhpCliTest extends TestCase
{
    private const JSON_MAX_DEPTH = 512;

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

var_dump($value);

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
        $ruleIds = $this->ruleIds($result->findings);
        self::assertContains('php.debug-output', $ruleIds);
        self::assertContains('php.empty-catch', $ruleIds);
        self::assertContains('php.pass-through-wrappers', $ruleIds);
        self::assertContains('php.placeholder-comments', $ruleIds);
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
            'overrides' => [[
                'path' => 'src/Example.php',
                'rules' => [
                    'php.placeholder-comments' => ['enabled' => false],
                    'php.debug-output' => ['weight' => 3.0],
                ],
            ]],
        ]));
        file_put_contents($this->fixtureDir . '/src/Ignore.php', "<?php\n// TODO ignored\n");

        $config = Config::load($this->fixtureDir);
        $result = (new Analyzer())->analyze($this->fixtureDir, $config, DefaultRegistry::create());

        self::assertSame(['src/Ignore.php'], $config['ignores']);
        self::assertSame(1, $config['thresholds']['unused']);
        self::assertSame([[
            'path' => 'src/Example.php',
            'rules' => [
                'php.placeholder-comments' => ['enabled' => false],
                'php.debug-output' => ['weight' => 3],
            ],
        ]], $config['overrides']);
        self::assertSame(['php.debug-output', 'php.pass-through-wrappers'], $this->ruleIds($result->findings));
        self::assertSame(3.75, $this->scoreForRule($result->findings, 'php.debug-output'));
        self::assertSame(1.0, $this->scoreForRule($result->findings, 'php.pass-through-wrappers'));
    }

    public function testConfigFallsBackToRepoConfigAndInvalidJsonFails(): void
    {
        $fixture = $this->makeFixture();
        file_put_contents($fixture . '/repo-slop.config.json', Json::encode([
            'ignores' => ['src/generated.php'],
            'rules' => ['php.directory-fanout-hotspot' => ['options' => ['fileCount' => 20]]],
        ]));

        $config = Config::load($fixture);

        self::assertSame(['src/generated.php'], $config['ignores']);
        self::assertSame(20, $config['rules']['php.directory-fanout-hotspot']['options']['fileCount']);

        file_put_contents($fixture . '/slop-scan.config.json', '{invalid');

        try {
            Config::load($fixture);
            self::fail('Expected invalid JSON to fail config loading.');
        } catch (\JsonException $exception) {
            self::assertNotSame('', $exception->getMessage());
        } finally {
            $this->remove($fixture);
        }
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
        file_put_contents($fixture . '/dupes/AgentLeftovers.php', "<?php\n// return transform(\$value);\nvar_dump(\$value);\n");

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame([
            'php.commented-out-code',
            'php.debug-output',
            'php.directory-fanout-hotspot',
            'php.duplicate-function-signatures',
            'php.error-swallowing',
            'php.over-fragmentation',
            'php.pass-through-wrappers',
        ], $this->ruleIds($result->findings));
        self::assertSame('many', $result->directoryScores[0]['path']);
        self::assertSame(17, $result->summary['fileCount']);
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

    public function testDiscovererSkipsUnsupportedFilesAndAnalyzerHandlesEmptyRepos(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/docs', 0777, true);
        file_put_contents($fixture . '/README.md', "# ignored\n");
        file_put_contents($fixture . '/docs/Guide.md', "# ignored\n");

        $discovery = Discoverer::discover($fixture, Config::defaults(), DefaultRegistry::create());
        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame([], $discovery['files']);
        self::assertSame([], $discovery['directories']);
        self::assertSame(0, $result->summary['fileCount']);
        self::assertSame(0, $result->summary['directoryCount']);
        self::assertSame(0, $result->summary['findingCount']);
        self::assertNull($result->summary['normalized']['scorePerFile']);
        self::assertNull($result->summary['normalized']['scorePerKloc']);
        self::assertNull($result->summary['normalized']['scorePerFunction']);
        self::assertSame([], $result->fileScores);
        self::assertSame([], $result->directoryScores);

        $report = $result->toReport();
        self::assertSame([], $report['files']);
        self::assertSame([], $report['directories']);

        $this->remove($fixture);
    }

    public function testAnalyzerLeavesNonMatchingRulesQuiet(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Clean.php', <<<'PHP'
<?php

// stable comment
function clean($value) {
    try {
        risky();
    } catch (Throwable $e) {
        return fallback($e);
    }

    return $value + 1;
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame([], $result->findings);
        self::assertSame('0 findings', (new LintReporter())->render($result));
        self::assertStringContainsString('"findingCount": 0', (new JsonReporter())->render($result));

        $this->remove($fixture);
    }

    public function testStaticAnalysisSuppressionDetection(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Ignored.php', <<<'PHP'
<?php

// @phpstan-ignore-next-line
risky($input);
// @phpstan-ignore-line
risky($input);
// @phpstan-ignore
risky($input);
        // @phpstan-ignore argument.type (expected during dependency upgrade)
        risky($input);
        // @psalm-suppress MixedAssignment
        $value = risky($input);
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertContains('php.blanket-static-analysis-suppressions', $this->ruleIds($result->findings));
        self::assertContains('php.excessive-static-analysis-suppressions', $this->ruleIds($result->findings));
        self::assertSame(3, $this->countForRule($result->findings, 'php.blanket-static-analysis-suppressions'));
        self::assertSame(1, $this->countForRule($result->findings, 'php.excessive-static-analysis-suppressions'));
        self::assertSame(
            ['suppressions=5', 'threshold=3', 'lines=3,5,7,9,11'],
            $this->firstEvidenceForRule($result->findings, 'php.excessive-static-analysis-suppressions')
        );

        $this->remove($fixture);
    }

    public function testCommentedOutCodeAndDebugOutputRulesDetectAgentLeftovers(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Leftovers.php', <<<'PHP'
<?php

function dump($value) {
    return $value;
}

// return transform($value);
print_r($value);
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertContains('php.commented-out-code', $this->ruleIds($result->findings));
        self::assertContains('php.debug-output', $this->ruleIds($result->findings));
        self::assertSame(1, $this->countForRule($result->findings, 'php.commented-out-code'));
        self::assertSame(1, $this->countForRule($result->findings, 'php.debug-output'));

        $this->remove($fixture);
    }

    public function testMockHeavyTestsWithoutAssertionsRuleDetectsMockOnlyTests(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/tests', 0777, true);
        file_put_contents($fixture . '/tests/OnlyMocksTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class OnlyMocksTest extends TestCase
{
    public function testBuildsMocksButAssertsNothing(): void
    {
        $first = $this->createMock(\stdClass::class);
        $second = $this->createMock(\ArrayObject::class);

        takes_dependencies($first, $second);
    }
}
PHP);
        file_put_contents($fixture . '/tests/ExpectationTest.php', <<<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class ExpectationTest extends TestCase
{
    public function testUsesMockExpectations(): void
    {
        $dependency = $this->createMock(\stdClass::class);
        $dependency->expects($this->once())->method('__toString');
    }
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertContains('php.mock-heavy-tests-without-assertions', $this->ruleIds($result->findings));
        self::assertSame(1, $this->countForRule($result->findings, 'php.mock-heavy-tests-without-assertions'));
        self::assertSame(
            ['tests=1', 'mocks=2', 'assertions=0', 'expectations=0'],
            $this->firstEvidenceForRule($result->findings, 'php.mock-heavy-tests-without-assertions')
        );

        $this->remove($fixture);
    }

    public function testPassThroughWrapperRuleSkipsFunctionsThatAddContext(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Helpers.php', <<<'PHP'
<?php

function encode_value($value, $pretty = false) {
    return json_encode($value, JSON_THROW_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertNotContains('php.pass-through-wrappers', $this->ruleIds($result->findings));

        $this->remove($fixture);
    }

    public function testMisleadingPhpDocTypeRuleDetectsConflictsAndRedundantDocsButSkipsHelpfulDetail(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Docs.php', <<<'PHP'
<?php

/**
 * @param string $name
 * @return int
 */
function redundant(string $name): int {
    return 1;
}

/**
 * @param string $count
 */
function wrong_param(int $count): int {
    return $count;
}

/**
 * @return string
 */
function wrong_return(): int {
    return 1;
}

/**
 * @param array<int, string> $items
 * @return non-empty-string
 */
function helpful(array $items): string {
    return 'ok';
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertContains('php.misleading-phpdoc-types', $this->ruleIds($result->findings));
        self::assertSame(4, $this->countForRule($result->findings, 'php.misleading-phpdoc-types'));
        self::assertSame(
            [
                'subject=redundant',
                'annotation=@param $name',
                'native=string',
                'phpdoc=string $name',
                'reason=phpdoc-repeats-native-type',
            ],
            $this->findEvidenceForRuleAndLine($result->findings, 'php.misleading-phpdoc-types', 7)
        );
        self::assertSame(
            [
                'subject=wrong_param',
                'annotation=@param $count',
                'native=int',
                'phpdoc=string $count',
                'reason=phpdoc-disagrees-with-native-type',
            ],
            $this->findEvidenceForRuleAndLine($result->findings, 'php.misleading-phpdoc-types', 14)
        );
        self::assertSame(
            [
                'subject=wrong_return',
                'annotation=@return',
                'native=int',
                'phpdoc=string',
                'reason=phpdoc-disagrees-with-native-type',
            ],
            $this->findEvidenceForRuleAndLine($result->findings, 'php.misleading-phpdoc-types', 21)
        );
        self::assertSame(0, count(array_filter(
            $result->findings,
            static fn(Finding $finding): bool => $finding->ruleId === 'php.misleading-phpdoc-types' && in_array('subject=helpful', $finding->evidence, true)
        )));

        $this->remove($fixture);
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function positiveFixtureProvider(): array
    {
        return [
            'empty catch' => ['empty-catch.fixture', 'src/EmptyCatch.php', 'php.empty-catch'],
            'exception wrap without previous' => ['exception-wrap-without-previous.fixture', 'src/ExceptionWrapWithoutPrevious.php', 'php.exception-wrap-without-previous'],
            'error obscuring catch' => ['error-obscuring-catch.fixture', 'src/ErrorObscuringCatch.php', 'php.error-obscuring-catch'],
            'error swallowing' => ['error-swallowing.fixture', 'src/ErrorSwallowing.php', 'php.error-swallowing'],
            'blanket suppression' => ['blanket-suppression.fixture', 'src/BlanketSuppression.php', 'php.blanket-static-analysis-suppressions'],
            'commented out code' => ['commented-out-code.fixture', 'src/CommentedOutCode.php', 'php.commented-out-code'],
            'catch default fallback' => ['catch-default-fallback.fixture', 'src/CatchDefaultFallback.php', 'php.catch-default-fallbacks'],
            'catch returns exception message' => ['catch-returns-exception-message.fixture', 'src/CatchReturnsExceptionMessage.php', 'php.catch-returns-exception-message'],
            'debug output' => ['debug-output.fixture', 'src/DebugOutput.php', 'php.debug-output'],
            'mock heavy test without assertions' => ['mock-heavy-test-without-assertions.fixture', 'tests/OnlyMocksTest.php', 'php.mock-heavy-tests-without-assertions'],
            'misleading phpdoc types' => ['misleading-phpdoc-types.fixture', 'src/MisleadingPhpDoc.php', 'php.misleading-phpdoc-types'],
            'placeholder comments' => ['placeholder-comments.fixture', 'src/PlaceholderComments.php', 'php.placeholder-comments'],
            'pass through wrapper' => ['pass-through-wrapper.fixture', 'src/PassThroughWrapper.php', 'php.pass-through-wrappers'],
        ];
    }

    #[DataProvider('positiveFixtureProvider')]
    public function testPositiveFixturesProduceSingleExpectedFinding(string $fixtureName, string $relativePath, string $expectedRuleId): void
    {
        $result = $this->scanStoredFixture('slop', $fixtureName, $relativePath);

        self::assertSame([$expectedRuleId], $this->ruleIds($result->findings));
        self::assertCount(1, $result->findings);
        self::assertSame($expectedRuleId, $result->findings[0]->ruleId);
        self::assertSame($relativePath, $result->findings[0]->path);
        self::assertSame($relativePath, $result->findings[0]->locations[0]['path'] ?? null);
        self::assertNotSame([], $result->findings[0]->evidence);
        self::assertSame(1, $result->findings[0]->deltaIdentity['fingerprintVersion']);
        self::assertSame($relativePath, $result->findings[0]->deltaIdentity['occurrences'][0]['path'] ?? null);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function cleanFixtureProvider(): array
    {
        return [
            'handled catch with return' => ['handled-catch-return.fixture', 'src/HandledCatch.php'],
            'exception wrap with previous' => ['exception-wrap-with-previous.fixture', 'src/ExceptionWrapWithPrevious.php'],
            'error wrapping with previous' => ['error-wrapping-with-previous.fixture', 'src/ErrorWrappingWithPrevious.php'],
            'test with mocks and assertions' => ['test-with-mocks-and-real-assertions.fixture', 'tests/MockAssertionsTest.php'],
            'helpful phpdoc types' => ['helpful-phpdoc-types.fixture', 'src/HelpfulPhpDoc.php'],
        ];
    }

    #[DataProvider('cleanFixtureProvider')]
    public function testCleanFixturesStayQuiet(string $fixtureName, string $relativePath): void
    {
        $result = $this->scanStoredFixture('clean', $fixtureName, $relativePath);

        self::assertSame([], $result->findings);
        self::assertSame([], $this->ruleIds($result->findings));
        self::assertSame(0, $result->summary['findingCount']);
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function expectedSnapshotProvider(): array
    {
        return [
            'empty catch snapshot' => ['empty-catch.fixture', 'src/EmptyCatch.php', 'empty-catch.json'],
            'blanket suppression snapshot' => ['blanket-suppression.fixture', 'src/BlanketSuppression.php', 'blanket-suppression.json'],
        ];
    }

    #[DataProvider('expectedSnapshotProvider')]
    public function testSelectedFixturesMatchStableJsonSnapshots(string $fixtureName, string $relativePath, string $expectedSnapshot): void
    {
        $result = $this->scanStoredFixture('slop', $fixtureName, $relativePath);
        $expected = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/expected/' . $expectedSnapshot),
            true,
            self::JSON_MAX_DEPTH,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($expected, $this->normalizedFindingSnapshot($result->findings));
    }

    public function testStackedStaticAnalysisSuppressionsRuleDetectsClusteredSuppressions(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Stacked.php', <<<'PHP'
<?php

// @psalm-suppress MixedAssignment
// @psalm-suppress MixedArgument
$value = risky($input);

// @psalm-suppress MixedAssignment
$safe = already_documented($input);
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertContains('php.stacked-static-analysis-suppressions', $this->ruleIds($result->findings));
        self::assertSame(1, $this->countForRule($result->findings, 'php.stacked-static-analysis-suppressions'));
        $evidence = $this->firstEvidenceForRule($result->findings, 'php.stacked-static-analysis-suppressions');
        self::assertStringContainsString('suppressions=2', $evidence[0]);
        self::assertNotContains('php.excessive-static-analysis-suppressions', $this->ruleIds($result->findings));

        $this->remove($fixture);
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

    public function testDataModelReportsAndDeltaNoChangeContracts(): void
    {
        $file = new FileRecord('src/One.php', '/tmp/One.php', '.php', 3, 2, null);
        $directory = new DirectoryRecord('src', ['src/One.php']);
        $finding = new Finding('rule.id', 'family', 'medium', 'repo', 'message', [], 1.25, [], null);
        $result = new AnalysisResult(
            '/repo',
            Config::defaults(),
            [
                'fileCount' => 1,
                'directoryCount' => 1,
                'findingCount' => 1,
                'repoScore' => 1.25,
                'physicalLineCount' => 3,
                'logicalLineCount' => 2,
                'functionCount' => 0,
                'normalized' => [
                    'scorePerFile' => 1.25,
                    'scorePerKloc' => 625.0,
                    'scorePerFunction' => null,
                    'findingsPerFile' => 1.0,
                    'findingsPerKloc' => 500.0,
                    'findingsPerFunction' => null,
                ],
            ],
            [$file],
            [$directory],
            [$finding],
            [['path' => 'src/One.php', 'score' => 1.25, 'findingCount' => 1]],
            [],
            1.25,
        );

        self::assertSame([
            'path' => 'src/One.php',
            'extension' => '.php',
            'lineCount' => 3,
            'languageId' => null,
        ], $file->toReport());
        self::assertSame([
            'path' => 'src',
            'filePaths' => ['src/One.php'],
        ], $directory->toReport());
        self::assertSame('rule.id', $finding->toReport()['ruleId']);
        self::assertSame(1, $finding->toReport()['deltaIdentity']['fingerprintVersion']);
        self::assertSame('<repo>', $finding->toReport()['deltaIdentity']['occurrences'][0]['path']);
        self::assertSame(1, $finding->toReport()['deltaIdentity']['occurrences'][0]['line']);
        self::assertSame('{"ok":true}', Json::encode(['ok' => true]));

        $report = $result->toReport();

        self::assertSame('/repo', $report['rootDir']);
        self::assertSame($result->config, $report['config']);
        self::assertSame($result->summary, $report['summary']);
        self::assertSame([$file->toReport()], $report['files']);
        self::assertSame([$directory->toReport()], $report['directories']);
        self::assertSame([$finding->toReport()], $report['findings']);
        self::assertSame($result->fileScores, $report['fileScores']);
        self::assertSame($result->directoryScores, $report['directoryScores']);
        self::assertSame(hash('sha256', Json::encode($result->config)), $report['metadata']['configHash']);

        $delta = Delta::diff(['findings' => [$finding->toReport()]], ['findings' => [$finding->toReport()]]);

        self::assertSame(['added' => 0, 'resolved' => 0], $delta['summary']);
        self::assertSame([], $delta['changes']);
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

    public function testAnalyzerReusesCachedPhpStructureFactsBetweenRuns(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
// TODO cached
function proxy($value) {
    return transform($value);
}

try {
    risky();
} catch (Throwable $e) {
}
PHP);
        $cacheFile = $fixture . '/.slop-scan.cache.json';
        $parserCalls = 0;
        PhpFacts::useParserFactoryForTesting(static function () use (&$parserCalls): Parser {
            $parserCalls++;

            return (new ParserFactory())->createForHostVersion();
        });

        try {
            $first = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create(), $cacheFile);
            $firstParserCalls = $parserCalls;
            $parserCalls = 0;
            $second = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create(), $cacheFile);

            self::assertGreaterThan(0, $firstParserCalls);
            self::assertSame(0, $parserCalls);
            self::assertFileExists($cacheFile);
            self::assertSame($first->summary, $second->summary);
            self::assertSame(
                array_map(static fn(Finding $finding): array => $finding->toReport(), $first->findings),
                array_map(static fn(Finding $finding): array => $finding->toReport(), $second->findings)
            );
        } finally {
            PhpFacts::useParserFactoryForTesting(null);
            $this->remove($fixture);
        }
    }

    public function testAnalyzerInvalidatesCachedPhpStructureFactsAfterFileChange(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
function proxy($value) {
    return transform($value);
}
PHP);
        $cacheFile = $fixture . '/.slop-scan.cache.json';
        $parserCalls = 0;
        PhpFacts::useParserFactoryForTesting(static function () use (&$parserCalls): Parser {
            $parserCalls++;

            return (new ParserFactory())->createForHostVersion();
        });

        try {
            (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create(), $cacheFile);
            $parserCalls = 0;
            file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
function proxy($value) {
    return transform($value);
}

try {
    risky();
} catch (Throwable $e) {
}
PHP);

            $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create(), $cacheFile);

            self::assertGreaterThan(0, $parserCalls);
            self::assertContains('php.empty-catch', $this->ruleIds($result->findings));
        } finally {
            PhpFacts::useParserFactoryForTesting(null);
            $this->remove($fixture);
        }
    }

    public function testCliNoArgsShowHelpAndErrorsGoToStderr(): void
    {
        [$helpExit, $helpOutput, $helpError] = $this->runCommandDetailed([]);
        [$unknownExit, $unknownOutput, $unknownError] = $this->runCommandDetailed(['unknown']);

        self::assertSame(0, $helpExit);
        self::assertSame('', $helpError);
        self::assertStringContainsString('Usage:', $helpOutput);
        self::assertSame(1, $unknownExit);
        self::assertSame('', $unknownOutput);
        self::assertStringContainsString('Unknown command: unknown', $unknownError);
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

    public function testCliScanBaselineReportsOnlyNewFindingsAndGithubAnnotations(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
// TODO baseline
function proxy($value) {
    return transform($value);
}
PHP);
        $baselineFile = $fixture . '/slop-baseline.json';

        [$generateExit, $generateOutput] = $this->runCommand(['scan', $fixture, '--baseline-file', $baselineFile, '--generate-baseline']);
        $baselineDecoded = json_decode((string) file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
// TODO baseline
function proxy($value) {
    return transform($value);
}
try {
    risky();
} catch (Throwable $e) {
}
PHP);
        [$jsonExit, $jsonOutput] = $this->runCommand(['scan', $fixture, '--baseline-file', $baselineFile, '--json']);
        [$githubExit, $githubOutput] = $this->runCommand(['scan', $fixture, '--baseline-file', $baselineFile, '--github']);
        [$lintExit, $lintOutput] = $this->runCommand(['scan', $fixture, '--baseline-file', $baselineFile, '--lint']);

        self::assertSame(0, $generateExit);
        self::assertStringContainsString('baseline written', $generateOutput);
        self::assertFileExists($baselineFile);
        self::assertSame(['metadata', 'summary', 'findings'], array_keys($baselineDecoded));
        self::assertSame('baseline', $baselineDecoded['metadata']['kind']);
        self::assertSame(count($baselineDecoded['findings']), $baselineDecoded['summary']['findingCount']);
        self::assertSame(1, $jsonExit);
        $decoded = json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $decoded['baseline']['summary']['added']);
        self::assertSame('php.empty-catch', $decoded['newFindings'][0]['ruleId']);
        self::assertSame(1, $githubExit);
        self::assertStringContainsString('::error file=src/A.php,line=8,col=1::Found empty PHP catch block (php.empty-catch)', $githubOutput);
        self::assertStringNotContainsString('php.placeholder-comments', $githubOutput);
        self::assertSame(1, $lintExit);
        self::assertStringContainsString('php.empty-catch', $lintOutput);
        self::assertStringNotContainsString('php.placeholder-comments', $lintOutput);

        $this->remove($fixture);
    }

    public function testGithubReporterEscapesValuesAndDefaultsMissingLocations(): void
    {
        $findingWithLocation = new Finding(
            'php.rule',
            'family',
            'medium',
            'file',
            "broken:value,\nnext",
            [],
            1.0,
            [['path' => 'src/{Odd},Name.php', 'line' => 7, 'column' => 3]],
            'src/{Odd},Name.php'
        );
        $findingWithoutLocation = new Finding(
            'php.other',
            'family',
            'weak',
            'file',
            "needs\rdefault",
            [],
            1.0,
            [],
            'src/Fallback.php'
        );
        $result = new AnalysisResult('/repo', [], ['findingCount' => 2], [], [], [$findingWithLocation, $findingWithoutLocation], [], [], 0.0);

        $rendered = (new GithubReporter())->render($result);

        self::assertMatchesRegularExpression('/^::error file=src\/%7BOdd%7D%2CName\.php,line=7,col=3::/m', $rendered);
        self::assertStringContainsString('::error file=src/%7BOdd%7D%2CName.php,line=7,col=3::broken%3Avalue%2C%0Anext (php.rule)', $rendered);
        self::assertStringContainsString('::error file=src/Fallback.php,line=1,col=1::needs%0Ddefault (php.other)', $rendered);
        self::assertSame('github', (new GithubReporter())->id());
    }

    public function testCommandSupportHelpersAndBaselineValidation(): void
    {
        $finding = new Finding('php.added', 'family', 'medium', 'file', 'Added finding', [], 1.0, [['path' => 'src/New.php', 'line' => 2]], 'src/New.php');
        $report = [
            'metadata' => ['schemaVersion' => 9, 'tool' => ['name' => 'custom', 'version' => '1.2.3'], 'configHash' => 'abc', 'findingFingerprintVersion' => 4],
            'findings' => [$finding->toReport()],
        ];
        $baseline = Baseline::fromReport($report);
        $baselinePath = $this->fixtureDir . '/baseline.json';

        Baseline::writeReport($baselinePath, $baseline);
        $decoded = Baseline::readReport($baselinePath);
        $delta = [
            'summary' => ['added' => 1, 'resolved' => 0],
            'changes' => [
                ['status' => 'added', 'fingerprint' => $finding->deltaIdentity['occurrences'][0]['fingerprint'], 'finding' => $finding->toReport()],
                ['status' => 'resolved', 'fingerprint' => 'gone', 'finding' => ['ruleId' => 'php.gone']],
            ],
        ];

        self::assertSame('baseline', $decoded['metadata']['kind']);
        self::assertSame(1, $decoded['summary']['findingCount']);
        self::assertCount(1, Baseline::addedFindings([$finding], $delta));
        self::assertSame('php.added', Baseline::addedFindings([$finding], $delta)[0]->ruleId);
        self::assertSame([], Baseline::addedFindings([$finding], ['changes' => []]));
        self::assertSame(['added' => 1, 'resolved' => 0], Baseline::reportWithDelta($report, $delta)['baseline']['summary']);
        self::assertSame([$finding->toReport()], Baseline::reportWithDelta($report, $delta)['newFindings']);
        self::assertSame('baseline', CommandSupport::reportInput($baselinePath, null, [])['metadata']['kind']);
        self::assertSame(1, CommandSupport::reportInput($baselinePath, null, [])['summary']['findingCount']);
        self::assertSame('0 new findings', CommandSupport::formatFindings([]));
        self::assertStringContainsString('added php.added', CommandSupport::formatDelta($delta));
        self::assertSame('json', CommandSupport::scanReporterId(true, false, false));
        self::assertSame('github', CommandSupport::scanReporterId(false, true, false));
        self::assertSame('lint', CommandSupport::scanReporterId(false, false, true));
        self::assertSame('text', CommandSupport::scanReporterId(false, false, false));
        self::assertTrue(CommandSupport::shouldFail($delta, 'resolved,added'));
        self::assertFalse(CommandSupport::shouldFail($delta, null));
        self::assertFalse(CommandSupport::shouldFail($delta, ''));

        try {
            Baseline::readReport($this->fixtureDir . '/missing.json');
            self::fail('Expected missing baseline file to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }

        file_put_contents($this->fixtureDir . '/invalid.json', Json::encode(['summary' => []]));
        try {
            Baseline::readReport($this->fixtureDir . '/invalid.json');
            self::fail('Expected invalid baseline JSON report to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('not a slop-scan JSON report', $exception->getMessage());
        }

        try {
            Baseline::writeReport($this->fixtureDir . '/missing-dir/baseline.json', $baseline);
            self::fail('Expected write to missing directory to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('directory does not exist', $exception->getMessage());
        }

        try {
            CommandSupport::reportInput(null, null, []);
            self::fail('Expected missing delta input to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Missing delta input', $exception->getMessage());
        }
    }

    public function testInProcessScanAndDeltaCommandsCoverBranches(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
// TODO baseline
function wrapper($value) {
    return transform($value);
}
PHP);

        $scanTester = new CommandTester(new ScanCommand());
        $scanExit = $scanTester->execute(['path' => $fixture, '--json' => true]);
        $scanReport = json_decode($scanTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $baselinePath = $fixture . '/baseline.json';
        $baselineExit = $scanTester->execute(['path' => $fixture, '--baseline-file' => $baselinePath, '--generate-baseline' => true]);

        file_put_contents($fixture . '/src/A.php', <<<'PHP'
<?php
// TODO baseline
function wrapper($value) {
    return transform($value);
}
try {
    risky();
} catch (Throwable $e) {
}
PHP);

        $baselineJsonExit = $scanTester->execute(['path' => $fixture, '--baseline-file' => $baselinePath, '--json' => true]);
        $baselineJson = json_decode($scanTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $baselineGithubExit = $scanTester->execute(['path' => $fixture, '--baseline-file' => $baselinePath, '--github' => true]);
        $baselineGithub = $scanTester->getDisplay();
        $baselineLintExit = $scanTester->execute(['path' => $fixture, '--baseline-file' => $baselinePath, '--lint' => true]);
        $baselineLint = $scanTester->getDisplay();
        $missingBaselineExit = $scanTester->execute(['path' => $fixture, '--generate-baseline' => true]);

        self::assertSame(0, $scanExit);
        self::assertSame(2, $scanReport['summary']['findingCount']);
        self::assertSame(0, $baselineExit);
        self::assertFileExists($baselinePath);
        self::assertSame(1, $baselineJsonExit);
        self::assertSame(1, $baselineJson['baseline']['summary']['added']);
        self::assertSame(1, $baselineGithubExit);
        self::assertStringContainsString('php.empty-catch', $baselineGithub);
        self::assertSame(1, $baselineLintExit);
        self::assertStringContainsString('new finding', $baselineLint);
        self::assertSame(1, $missingBaselineExit);
        self::assertStringContainsString('Missing --baseline-file', $scanTester->getDisplay());

        $baseReport = $fixture . '/base-report.json';
        $headReport = $fixture . '/head-report.json';
        $finding = new Finding('php.new', 'family', 'medium', 'file', 'new', [], 1.0, [['path' => 'src/B.php', 'line' => 1]], 'src/B.php');
        file_put_contents($baseReport, Json::encode(['findings' => []]));
        file_put_contents($headReport, Json::encode(['findings' => [$finding->toReport()]]));

        $deltaTester = new CommandTester(new DeltaCommand());
        $deltaExit = $deltaTester->execute(['--base-report' => $baseReport, '--head-report' => $headReport, '--json' => true, '--fail-on' => 'added']);
        $deltaJson = json_decode($deltaTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $deltaTextExit = $deltaTester->execute(['base-path' => $fixture, 'head-path' => $fixture]);
        $deltaText = $deltaTester->getDisplay();
        $deltaErrorExit = $deltaTester->execute([]);

        self::assertSame(1, $deltaExit);
        self::assertSame(1, $deltaJson['summary']['added']);
        self::assertSame(0, $deltaTextExit);
        self::assertStringContainsString('slop-scan delta', $deltaText);
        self::assertSame(1, $deltaErrorExit);
        self::assertStringContainsString('Missing delta input', $deltaTester->getDisplay());

        $this->remove($fixture);
    }

    public function testScanCommandUsesDefaultCacheAndReusesCachedFacts(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', "<?php\nvar_dump(\$value);\n");
        $cacheFile = ScanCache::defaultPath($fixture);
        $parserCalls = 0;
        PhpFacts::useParserFactoryForTesting(static function () use (&$parserCalls): Parser {
            $parserCalls++;

            return (new ParserFactory())->createForHostVersion();
        });

        try {
            $scanTester = new CommandTester(new ScanCommand());
            $firstExit = $scanTester->execute(['path' => $fixture, '--json' => true]);
            $firstReport = json_decode($scanTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $firstParserCalls = $parserCalls;
            $parserCalls = 0;
            $secondExit = $scanTester->execute(['path' => $fixture, '--json' => true]);
            $secondReport = json_decode($scanTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(0, $firstExit);
            self::assertSame(0, $secondExit);
            self::assertGreaterThan(0, $firstParserCalls);
            self::assertSame(0, $parserCalls);
            self::assertFileExists($cacheFile);
            self::assertSame(['php.debug-output'], array_column($firstReport['findings'], 'ruleId'));
            self::assertSame(['php.debug-output'], array_column($secondReport['findings'], 'ruleId'));
        } finally {
            PhpFacts::useParserFactoryForTesting(null);
            $this->remove($fixture);
        }
    }

    public function testCliScanAndDeltaCreateDefaultCacheFiles(): void
    {
        $base = $this->makeFixture();
        $head = $this->makeFixture();
        mkdir($base . '/src', 0777, true);
        mkdir($head . '/src', 0777, true);
        file_put_contents($base . '/src/A.php', "<?php\nfunction clean_base() { return 1; }\n");
        file_put_contents($head . '/src/A.php', "<?php\n// TODO added\nfunction clean_head() { return 1; }\n");

        [$scanExit] = $this->runCommand(['scan', $head, '--json']);
        [$deltaExit] = $this->runCommand(['delta', '--base', $base, '--head', $head, '--fail-on', 'added']);

        self::assertSame(0, $scanExit);
        self::assertSame(1, $deltaExit);
        self::assertFileExists(ScanCache::defaultPath($head));
        self::assertFileExists(ScanCache::defaultPath($base));

        $this->remove($base);
        $this->remove($head);
    }

    public function testScanCommandCacheOptionOverridesDefaultCacheLocation(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/A.php', "<?php\nvar_dump(\$value);\n");
        $customCacheFile = $fixture . '/custom-cache.json';

        try {
            $scanTester = new CommandTester(new ScanCommand());
            $exit = $scanTester->execute(['path' => $fixture, '--json' => true, '--cache-file' => $customCacheFile]);

            self::assertSame(0, $exit);
            self::assertFileExists($customCacheFile);
            self::assertFileDoesNotExist(ScanCache::defaultPath($fixture));
        } finally {
            $this->remove($fixture);
        }
    }

    public function testApplicationRendersThrowableMessagesToStandardAndErrorOutputs(): void
    {
        $application = new SlopScanApplication();
        $buffer = new BufferedOutput();
        $consoleOutput = new ConsoleOutputStub();

        $application->renderThrowable(new \RuntimeException('plain failure'), $buffer);
        $application->renderThrowable(new CommandNotFoundException('Command "mystery" is not defined.'), $consoleOutput);

        self::assertStringContainsString('plain failure', $buffer->fetch());
        self::assertSame('', $consoleOutput->fetch());
        self::assertStringContainsString('Unknown command: mystery', $consoleOutput->getErrorOutput()->fetch());
    }

    public function testPatternMatchingLanguageRegistryAndSchedulerEdges(): void
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerReporter(new JsonReporter());
        $registry->registerReporter(new GithubReporter());

        self::assertTrue(PatternMatcher::matches('src/Foo.php', 'src/*.php'));
        self::assertTrue(PatternMatcher::ignored('vendor/pkg/Foo.php', ['**/vendor/**']));
        self::assertFalse(PatternMatcher::ignored('src/Foo.php', ['**/vendor/**']));
        self::assertFalse(PatternMatcher::matches('src/Foo.php', 'tests/*.php'));
        self::assertSame('php', $registry->detectLanguage('template.phtml')?->id());
        self::assertNull($registry->detectLanguage('README.md'));
        self::assertSame('json', $registry->reporter('json')->id());
        self::assertSame('github', $registry->reporter('github')->id());
        self::assertSame('php', $registry->languages()[0]->id());
        self::assertSame(0, count($registry->factProviders()));
        self::assertSame(0, count($registry->rules()));

        $this->expectException(\RuntimeException::class);
        $registry->reporter('missing');
    }

    public function testSchedulerDetectsUnresolvedProviderDependencies(): void
    {
        $provider = new class implements FactProvider {
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

    public function testLinesFactStoreAndPhpFactsCoverEmptyAndValidContracts(): void
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

        self::assertNull($store->getRepoFact('missing'));
        self::assertNull($store->getDirectoryFact('missing', 'directory.fact'));
        self::assertNull($store->getFileFact('src/Missing.php', 'two'));
        self::assertSame([], $store->listFilePathsWithFact('missing'));
        $store->setRepoFact('repo.fact', true);
        $store->setDirectoryFact('src', 'directory.fact', 2);
        $store->setFileFacts('src/Facts.php', ['one' => 1]);
        $store->setFileFact('src/Facts.php', 'two', 2);

        self::assertSame(0, Lines::physical(''));
        self::assertSame(2, Lines::physical("a\nb"));
        self::assertSame(0, Lines::logical(''));
        self::assertSame(0, Lines::logical("// comment\n* doc line"));
        self::assertSame(13, $record->lineCount);
        self::assertSame(['src/Facts.php'], $directory->toReport()['filePaths']);
        self::assertTrue($store->getRepoFact('repo.fact'));
        self::assertSame(2, $store->getDirectoryFact('src', 'directory.fact'));
        self::assertSame(2, $store->getFileFact('src/Facts.php', 'two'));
        self::assertSame(['src/Facts.php'], $store->listFilePathsWithFact('one'));
        self::assertSame([], PhpFacts::comments("<?php\nfunction clean() {}\n"));
        self::assertSame([], PhpFacts::functions("<?php\n\$value = 1;\n"));
        self::assertSame([], PhpFacts::tryCatches("<?php\nfunction clean() { return 1; }\n"));
        self::assertSame(['// TODO comment'], array_column(PhpFacts::comments($php), 'text'));
        self::assertSame(['box::value(1)', 'value(1)'], array_column($functions, 'signature'));
        self::assertSame(11, $catches[0]['line']);
        self::assertSame(['$input'], $functions[0]['params']);
        self::assertSame(['callee' => 'wrap', 'args' => ['$input']], $functions[0]['passThroughCall']);
        self::assertSame('', trim($catches[0]['body']));
        self::assertSame(0, $catches[0]['statementCount']);
        self::assertFalse($catches[0]['hasThrow']);
        self::assertFalse($catches[0]['hasReturn']);
        self::assertSame([], $catches[0]['callNames']);
        self::assertSame([], $catches[0]['defaultReturnKinds']);
        self::assertSame([], $catches[0]['thrownExceptions']);
        self::assertArrayHasKey('available', $summary);
        self::assertArrayHasKey('classCount', $summary);
        self::assertArrayHasKey('functionCount', $summary);
    }

    public function testPhpFactsIgnoreCodeLikeTextInsideCommentsAndStrings(): void
    {
        $php = <<<'PHP'
<?php
// function ignored($value) { return wrap($value); }
$fixture = <<<'FIXTURE'
<?php
function proxy($value) {
    return transform($value);
}
try {
    risky();
} catch (Throwable $e) {
    error_log($e->getMessage());
}
FIXTURE;
function clean($value) {
    return keep($value);
}
try {
    risky();
} catch (Throwable $e) {
    return fallback($e);
}
PHP;

        $functions = PhpFacts::functions($php);
        $catches = PhpFacts::tryCatches($php);

        self::assertSame(['clean(1)'], array_column($functions, 'signature'));
        self::assertSame(1, count($catches));
        self::assertSame(['callee' => 'keep', 'args' => ['$value']], $functions[0]['passThroughCall']);
        self::assertTrue($catches[0]['hasReturn']);
        self::assertSame([], $catches[0]['defaultReturnKinds']);
        self::assertStringContainsString('return fallback', $catches[0]['body']);
    }

    public function testCatchDefaultFallbackRuleDetectsLiteralFallbacksOnly(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/Fallbacks.php', <<<'PHP'
<?php

function fallback_array(string $key): array
{
    try {
        return load_array($key);
    } catch (Throwable $exception) {
        return [];
    }
}

function fallback_null(string $key): mixed
{
    try {
        return load_value($key);
    } catch (Throwable $exception) {
        return null;
    }
}

function delegated_fallback(string $key): mixed
{
    try {
        return load_value($key);
    } catch (Throwable $exception) {
        return recover_from_cache($exception);
    }
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame(2, $this->countForRule($result->findings, 'php.catch-default-fallbacks'));
        self::assertSame(['return=empty-array'], $this->findEvidenceForRuleAndLine($result->findings, 'php.catch-default-fallbacks', 7));
        self::assertSame(['return=null'], $this->findEvidenceForRuleAndLine($result->findings, 'php.catch-default-fallbacks', 16));

        $this->remove($fixture);
    }

    public function testCatchReturnsExceptionMessageRuleDetectsCaughtMessageReturnsOnly(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/CatchReturnsMessage.php', <<<'PHP'
<?php

function returns_message(Throwable $input): string
{
    try {
        return load_message($input);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function returns_stringified(Throwable $input): string
{
    try {
        return load_message($input);
    } catch (Throwable $exception) {
        return (string) $exception;
    }
}

function returns_recovery(Throwable $input): string
{
    try {
        return load_message($input);
    } catch (Throwable $exception) {
        return recover_from_cache($exception);
    }
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame(2, $this->countForRule($result->findings, 'php.catch-returns-exception-message'));
        self::assertSame(['return=caught-message'], $this->findEvidenceForRuleAndLine($result->findings, 'php.catch-returns-exception-message', 7));
        self::assertSame(['return=caught-string'], $this->findEvidenceForRuleAndLine($result->findings, 'php.catch-returns-exception-message', 16));

        $this->remove($fixture);
    }

    public function testErrorObscuringCatchRuleFlagsGenericReplacementWithoutPrevious(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/ErrorWrap.php', <<<'PHP'
<?php

function hides_original(Throwable $input): never
{
    try {
        risky($input);
    } catch (Throwable $exception) {
        throw new RuntimeException($exception->getMessage());
    }
}

function keeps_previous(Throwable $input): never
{
    try {
        risky($input);
    } catch (Throwable $exception) {
        throw new RuntimeException('failed', 0, $exception);
    }
}

function throws_domain_exception(Throwable $input): never
{
    try {
        risky($input);
    } catch (Throwable $exception) {
        throw new ProjectFailure('failed', previous: $exception);
    }
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame(1, $this->countForRule($result->findings, 'php.error-obscuring-catch'));
        self::assertSame(
            ['class=RuntimeException', 'reason=generic-replacement-without-previous'],
            $this->findEvidenceForRuleAndLine($result->findings, 'php.error-obscuring-catch', 7)
        );

        $this->remove($fixture);
    }

    public function testExceptionWrapWithoutPreviousRuleFlagsCustomReplacementThatDropsPrevious(): void
    {
        $fixture = $this->makeFixture();
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/ExceptionWrap.php', <<<'PHP'
<?php

function hides_previous(Throwable $input): never
{
    try {
        risky($input);
    } catch (Throwable $exception) {
        throw new ProjectFailure($exception->getMessage());
    }
}

function keeps_previous(Throwable $input): never
{
    try {
        risky($input);
    } catch (Throwable $exception) {
        throw new ProjectFailure($exception->getMessage(), previous: $exception);
    }
}
PHP);

        $result = (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());

        self::assertSame(1, $this->countForRule($result->findings, 'php.exception-wrap-without-previous'));
        self::assertSame(
            ['class=ProjectFailure', 'reason=wraps-caught-exception-without-previous'],
            $this->findEvidenceForRuleAndLine($result->findings, 'php.exception-wrap-without-previous', 7)
        );

        $this->remove($fixture);
    }

    public function testPhpFactsSummarizeDefaultFallbacksAndReplacementThrows(): void
    {
        $php = <<<'PHP'
<?php

function message_fallback(): string
{
    try {
        return risky();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function fallback(): mixed
{
    try {
        return risky();
    } catch (Throwable $exception) {
        return null;
    }
}

function wrapped(): never
{
    try {
        risky();
    } catch (Throwable $exception) {
        throw new RuntimeException($exception->getMessage());
    }
}

function preserved(): never
{
    try {
        risky();
    } catch (Throwable $exception) {
        throw new RuntimeException('failed', previous: $exception);
    }
}
PHP;

        $catches = PhpFacts::tryCatches($php);

        self::assertSame([], $catches[0]['defaultReturnKinds']);
        self::assertSame(['caught-message'], $catches[0]['returnedCaughtValueKinds']);
        self::assertSame(
            ['null'],
            $catches[1]['defaultReturnKinds']
        );
        self::assertSame([], $catches[1]['returnedCaughtValueKinds']);
        self::assertSame([], $catches[2]['returnedCaughtValueKinds']);
        self::assertSame([], $catches[3]['returnedCaughtValueKinds']);
        self::assertSame([], $catches[0]['thrownExceptions']);
        self::assertSame([], $catches[1]['thrownExceptions']);
        self::assertSame(
            [[
                'class' => 'RuntimeException',
                'isGeneric' => true,
                'preservesPrevious' => false,
                'usesCaughtVariable' => true,
            ]],
            $catches[2]['thrownExceptions']
        );
        self::assertSame(
            [[
                'class' => 'RuntimeException',
                'isGeneric' => true,
                'preservesPrevious' => true,
                'usesCaughtVariable' => true,
            ]],
            $catches[3]['thrownExceptions']
        );
    }

    public function testPhpFactsCollectDebugCallsFromAstOnly(): void
    {
        $php = <<<'PHP'
<?php
function var_dump($value) {
    return $value;
}

final class Debugger
{
    public function dumpValue(mixed $value): void
    {
        $this->var_dump($value);
        self::print_r($value);
    }
}

// var_dump($comment);
$doc = 'print_r($string)';
var_dump($real);
print_r($other);
PHP;

        self::assertSame(
            [
                ['name' => 'var_dump', 'line' => 17],
                ['name' => 'print_r', 'line' => 18],
            ],
            PhpFacts::debugCalls($php)
        );
    }

    public function testPhpFactsCollectParserBackedTestCallSummary(): void
    {
        $php = <<<'PHP'
<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    #[Test]
    public function buildsMocks(): void
    {
        $dependency = $this->createMock(\stdClass::class);
        $builder = $this->getMockBuilder(\ArrayObject::class);
        $this->assertSame(1, 1);
        $dependency->expects($this->once());
    }

    public function test_helper(): void
    {
    }
}
PHP;

        self::assertSame(
            [
                'looksLikeTest' => true,
                'testCount' => 2,
                'mockCount' => 2,
                'assertionCount' => 1,
                'expectationCount' => 1,
            ],
            PhpFacts::testCallSummary($php, 'tests/ExampleTest.php')
        );
        self::assertSame(
            [
                'looksLikeTest' => false,
                'testCount' => 0,
                'mockCount' => 0,
                'assertionCount' => 0,
                'expectationCount' => 0,
            ],
            PhpFacts::testCallSummary("<?php\nfunction helper() {}\n", 'src/Helper.php')
        );
    }

    public function testPhpFactsCollectPhpDocTypeSummariesFromFunctionsAndMethods(): void
    {
        $file = $this->fixtureDir . '/src/PhpDocFacts.php';
        file_put_contents($file, <<<'PHP'
<?php

/**
 * @param string $name
 * @return string|null
 */
function format_name(string $name): ?string {
    return $name;
}

final class Formatter
{
    /**
     * @param int $count
     * @return array<int, string>
     */
    public function names(int $count): array
    {
        return [];
    }
}
PHP);

        $summaries = PhpFacts::phpDocTypeSummaries($file);

        self::assertSame(['format_name', 'Formatter::names'], array_column($summaries, 'subject'));
        self::assertSame('string', $summaries[0]['params'][0]['nativeType']);
        self::assertSame('string|null', $summaries[0]['return']['phpDocType']);
        self::assertSame('int', $summaries[1]['params'][0]['nativeType']);
        self::assertSame('array<int, string>', $summaries[1]['return']['phpDocExtendedType']);
    }

    public function testParserSummaryHandlesUnavailableInjectedAndErrorStates(): void
    {
        $file = $this->fixtureDir . '/src/ParserSummary.php';
        file_put_contents($file, "<?php\nclass Parsed {}\nfunction parsed() {}\n");

        if (!class_exists(\PhpParser\ParserFactory::class)) {
            $unavailable = PhpFacts::parserSummary($file);

            self::assertSame([
                'available' => false,
                'classCount' => 0,
                'functionCount' => 0,
            ], $unavailable);
        }

        PhpFacts::useParserFactoryForTesting(static fn(): Parser => new ParserStub());
        ParserStub::$statements = [
            new \PhpParser\Node\Stmt\Class_(new \PhpParser\Node\Identifier('Parsed')),
            new \PhpParser\Node\Stmt\Function_(new \PhpParser\Node\Identifier('parsed')),
        ];
        ParserStub::$exceptionMessage = null;

        try {
            $success = PhpFacts::parserSummary($file);

            self::assertSame([
                'available' => true,
                'classCount' => 1,
                'functionCount' => 1,
            ], $success);

            ParserStub::$exceptionMessage = 'parse failed';

            $error = PhpFacts::parserSummary($file);

            self::assertSame(true, $error['available']);
            self::assertSame(0, $error['classCount']);
            self::assertSame(0, $error['functionCount']);
            self::assertSame('parse failed', $error['error']);
        } finally {
            PhpFacts::useParserFactoryForTesting(null);
            ParserStub::$statements = [];
            ParserStub::$exceptionMessage = null;
        }
    }

    /**
     * @param list<Finding> $findings findings to inspect
     * @return list<string> unique sorted rule IDs
     */
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

    private function scanStoredFixture(string $group, string $fixtureName, string $relativePath): AnalysisResult
    {
        $fixture = $this->makeFixture();
        $sourcePath = __DIR__ . "/fixtures/{$group}/{$fixtureName}";
        $destinationPath = $fixture . '/' . $relativePath;
        mkdir(dirname($destinationPath), 0777, true);
        copy($sourcePath, $destinationPath);

        try {
            return (new Analyzer())->analyze($fixture, Config::defaults(), DefaultRegistry::create());
        } finally {
            $this->remove($fixture);
        }
    }

    /**
     * @param list<Finding> $findings findings to normalize
     * @return list<array{ruleId:string,path:?string,severity:string,evidence:list<string>,locations:list<array{path:string,line:int,column?:int}>,deltaIdentity:array<string,mixed>}>
     */
    private function normalizedFindingSnapshot(array $findings): array
    {
        return array_map($this->findingToNormalizedArray(...), $findings);
    }

    /**
     * @return array{ruleId:string,path:?string,severity:string,evidence:list<string>,locations:list<array{path:string,line:int,column?:int}>,deltaIdentity:array<string,mixed>}
     */
    private function findingToNormalizedArray(Finding $finding): array
    {
        return [
            'ruleId' => $finding->ruleId,
            'path' => $finding->path,
            'severity' => $finding->severity,
            'evidence' => $finding->evidence,
            'locations' => $finding->locations,
            'deltaIdentity' => $finding->deltaIdentity,
        ];
    }

    /**
     * @param list<Finding> $findings findings to inspect
     */
    private function scoreForRule(array $findings, string $ruleId): float
    {
        foreach ($findings as $finding) {
            if ($finding->ruleId === $ruleId) {
                return $finding->score;
            }
        }
        self::fail("Missing finding for {$ruleId}");
    }

    /**
     * @param list<Finding> $findings findings to inspect
     */
    private function countForRule(array $findings, string $ruleId): int
    {
        return count(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === $ruleId));
    }

    /**
     * @param list<Finding> $findings findings to inspect
     * @return list<string> evidence for the requested rule ID
     */
    private function firstEvidenceForRule(array $findings, string $ruleId): array
    {
        foreach ($findings as $finding) {
            if ($finding->ruleId === $ruleId) {
                return $finding->evidence;
            }
        }
        self::fail("Missing finding for {$ruleId}");
    }

    /**
     * @param list<Finding> $findings findings to inspect
     * @return list<string> evidence for the requested rule ID and line
     */
    private function findEvidenceForRuleAndLine(array $findings, string $ruleId, int $line): array
    {
        foreach ($findings as $finding) {
            if ($finding->ruleId === $ruleId && ($finding->locations[0]['line'] ?? null) === $line) {
                return $finding->evidence;
            }
        }

        self::fail("Missing finding for {$ruleId} on line {$line}");
    }

    /**
     * @param list<string> $arguments CLI arguments
     * @return array{0:int,1:string} exit code and captured stdout
     */
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

    /**
     * @param list<string> $arguments CLI arguments
     * @return array{0:int,1:string,2:string} exit code, captured stdout, and captured stderr
     */
    private function runCommandDetailed(array $arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/slop-scan.php'], $arguments);
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(array_map('strval', $command), $descriptor, $pipes, dirname(__DIR__));
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [$exit, (string) $output, (string) $error];
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

final class ParserStub implements Parser
{
    /** @var list<\PhpParser\Node\Stmt> */
    public static array $statements = [];
    public static ?string $exceptionMessage = null;

    public function parse(string $code, ?\PhpParser\ErrorHandler $errorHandler = null): array
    {
        if (self::$exceptionMessage !== null) {
            throw new \RuntimeException(self::$exceptionMessage);
        }
        return self::$statements;
    }

    public function getTokens(): array
    {
        return [];
    }
}

final class ConsoleOutputStub extends BufferedOutput implements \Symfony\Component\Console\Output\ConsoleOutputInterface
{
    private BufferedOutput $errorOutput;

    public function __construct()
    {
        parent::__construct();
        $this->errorOutput = new BufferedOutput();
    }

    public function getErrorOutput(): BufferedOutput
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(\Symfony\Component\Console\Output\OutputInterface $error): void
    {
        if (!$error instanceof BufferedOutput) {
            throw new \InvalidArgumentException('Expected BufferedOutput.');
        }

        $this->errorOutput = $error;
    }

    public function section(): \Symfony\Component\Console\Output\ConsoleSectionOutput
    {
        throw new \BadMethodCallException('Sections are not needed in tests.');
    }
}
