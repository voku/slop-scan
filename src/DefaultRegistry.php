<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Fact\DirectoryMetricsFactProvider;
use SlopScan\Fact\FunctionDuplicationFactProvider;
use SlopScan\Fact\PhpStructureFactProvider;
use SlopScan\Rule\LowSignalMarkdownRule;
use SlopScan\Reporter\GithubReporter;
use SlopScan\Reporter\JsonReporter;
use SlopScan\Reporter\LintReporter;
use SlopScan\Reporter\NdjsonReporter;
use SlopScan\Reporter\ToonReporter;
use SlopScan\Reporter\TextReporter;
use SlopScan\Rule\BlanketStaticAnalysisSuppressionsRule;
use SlopScan\Rule\CatchReturnsExceptionMessageRule;
use SlopScan\Rule\CloneClusterRule;
use SlopScan\Rule\CommentedOutCodeRule;
use SlopScan\Rule\CatchDefaultFallbacksRule;
use SlopScan\Rule\DebugOutputRule;
use SlopScan\Rule\DirectoryFanoutHotspotRule;
use SlopScan\Rule\DuplicateFunctionSignaturesRule;
use SlopScan\Rule\EmptyCatchRule;
use SlopScan\Rule\ExceptionWrapWithoutPreviousRule;
use SlopScan\Rule\ErrorObscuringCatchRule;
use SlopScan\Rule\ErrorSwallowingRule;
use SlopScan\Rule\ExcessiveStaticAnalysisSuppressionsRule;
use SlopScan\Rule\MockHeavyTestsWithoutAssertionsRule;
use SlopScan\Rule\MagicNumbersRule;
use SlopScan\Rule\MisleadingPhpDocTypesRule;
use SlopScan\Rule\OverFragmentationRule;
use SlopScan\Rule\PassThroughWrappersRule;
use SlopScan\Rule\PlaceholderCommentsRule;
use SlopScan\Rule\PlaceholderMethodBodiesRule;
use SlopScan\Rule\ReturnConstantStubRule;
use SlopScan\Rule\StackedStaticAnalysisSuppressionsRule;
use SlopScan\Rule\TypeEscapeHotspotsRule;

final class DefaultRegistry
{
    public static function create(): Registry
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerLanguage(new MarkdownLanguage());
        $registry->registerFactProvider(new PhpStructureFactProvider());
        $registry->registerFactProvider(new DirectoryMetricsFactProvider());
        $registry->registerFactProvider(new FunctionDuplicationFactProvider());
        foreach ([
            new EmptyCatchRule(),
            new ExceptionWrapWithoutPreviousRule(),
            new ErrorObscuringCatchRule(),
            new ErrorSwallowingRule(),
            new BlanketStaticAnalysisSuppressionsRule(),
            new ExcessiveStaticAnalysisSuppressionsRule(),
            new StackedStaticAnalysisSuppressionsRule(),
            new CommentedOutCodeRule(),
            new CatchDefaultFallbacksRule(),
            new CatchReturnsExceptionMessageRule(),
            new DebugOutputRule(),
            new MockHeavyTestsWithoutAssertionsRule(),
            new MagicNumbersRule(),
            new MisleadingPhpDocTypesRule(),
            new PlaceholderCommentsRule(),
            new PassThroughWrappersRule(),
            new DirectoryFanoutHotspotRule(),
            new OverFragmentationRule(),
            new DuplicateFunctionSignaturesRule(),
            new ReturnConstantStubRule(),
            new PlaceholderMethodBodiesRule(),
            new CloneClusterRule(),
            new TypeEscapeHotspotsRule(),
            new LowSignalMarkdownRule(),
        ] as $rule) {
            $registry->registerRule($rule);
        }
        $registry->registerReporter(new TextReporter());
        $registry->registerReporter(new JsonReporter());
        $registry->registerReporter(new ToonReporter());
        $registry->registerReporter(new NdjsonReporter());
        $registry->registerReporter(new LintReporter());
        $registry->registerReporter(new GithubReporter());
        return $registry;
    }
}
