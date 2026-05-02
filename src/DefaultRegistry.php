<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Fact\DirectoryMetricsFactProvider;
use SlopScan\Fact\FunctionDuplicationFactProvider;
use SlopScan\Fact\PhpStructureFactProvider;
use SlopScan\Fact\RepoToolingFactProvider;
use SlopScan\Reporter\GithubReporter;
use SlopScan\Reporter\JsonReporter;
use SlopScan\Reporter\LintReporter;
use SlopScan\Reporter\TextReporter;
use SlopScan\Rule\BlanketStaticAnalysisSuppressionsRule;
use SlopScan\Rule\CommentedOutCodeRule;
use SlopScan\Rule\DebugOutputRule;
use SlopScan\Rule\DirectoryFanoutHotspotRule;
use SlopScan\Rule\DuplicateFunctionSignaturesRule;
use SlopScan\Rule\EmptyCatchRule;
use SlopScan\Rule\ErrorSwallowingRule;
use SlopScan\Rule\ExcessiveStaticAnalysisSuppressionsRule;
use SlopScan\Rule\InfectionStaticAnalysisIntegrationRule;
use SlopScan\Rule\MockHeavyTestsWithoutAssertionsRule;
use SlopScan\Rule\MisleadingPhpDocTypesRule;
use SlopScan\Rule\OverFragmentationRule;
use SlopScan\Rule\PassThroughWrappersRule;
use SlopScan\Rule\PlaceholderCommentsRule;
use SlopScan\Rule\StackedStaticAnalysisSuppressionsRule;

final class DefaultRegistry
{
    public static function create(): Registry
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerFactProvider(new PhpStructureFactProvider());
        $registry->registerFactProvider(new DirectoryMetricsFactProvider());
        $registry->registerFactProvider(new FunctionDuplicationFactProvider());
        $registry->registerFactProvider(new RepoToolingFactProvider());
        foreach ([
            new EmptyCatchRule(),
            new ErrorSwallowingRule(),
            new BlanketStaticAnalysisSuppressionsRule(),
            new ExcessiveStaticAnalysisSuppressionsRule(),
            new StackedStaticAnalysisSuppressionsRule(),
            new CommentedOutCodeRule(),
            new DebugOutputRule(),
            new MockHeavyTestsWithoutAssertionsRule(),
            new MisleadingPhpDocTypesRule(),
            new PlaceholderCommentsRule(),
            new PassThroughWrappersRule(),
            new DirectoryFanoutHotspotRule(),
            new OverFragmentationRule(),
            new DuplicateFunctionSignaturesRule(),
            new InfectionStaticAnalysisIntegrationRule(),
        ] as $rule) {
            $registry->registerRule($rule);
        }
        $registry->registerReporter(new TextReporter());
        $registry->registerReporter(new JsonReporter());
        $registry->registerReporter(new LintReporter());
        $registry->registerReporter(new GithubReporter());
        return $registry;
    }
}
