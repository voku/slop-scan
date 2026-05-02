<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use PhpParser\Parser;
use SlopScan\Analyzer;
use SlopScan\Config;
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
            'overrides' => [['path' => 'src/Example.php']],
        ]));
        file_put_contents($this->fixtureDir . '/src/Ignore.php', "<?php\n// TODO ignored\n");

        $config = Config::load($this->fixtureDir);
        $result = (new Analyzer())->analyze($this->fixtureDir, $config, DefaultRegistry::create());

        self::assertSame(['src/Ignore.php'], $config['ignores']);
        self::assertSame(1, $config['thresholds']['unused']);
        self::assertSame([['path' => 'src/Example.php']], $config['overrides']);
        self::assertSame(['php.debug-output', 'php.pass-through-wrappers', 'php.placeholder-comments'], $this->ruleIds($result->findings));
        self::assertSame(1.25, $this->scoreForRule($result->findings, 'php.debug-output'));
        self::assertSame(1.0, $this->scoreForRule($result->findings, 'php.pass-through-wrappers'));
        self::assertSame(1.0, $this->scoreForRule($result->findings, 'php.placeholder-comments'));
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
        self::assertSame('', trim($catches[0]['body']));
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
        self::assertStringContainsString('return fallback', $catches[0]['body']);
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

    /**
     * @param list<Finding> $findings findings to inspect
     * @return float score for the requested rule ID
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

    public function parse(string $code, ?\PhpParser\ErrorHandler $errorHandler = null): ?array
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
