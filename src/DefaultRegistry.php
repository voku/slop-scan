<?php

declare(strict_types=1);

namespace SlopScan;

final class DefaultRegistry
{
    public static function create(): Registry
    {
        $registry = new Registry();
        $registry->registerLanguage(new PhpLanguage());
        $registry->registerFactProvider(new PhpStructureFactProvider());
        $registry->registerFactProvider(new DirectoryMetricsFactProvider());
        $registry->registerFactProvider(new FunctionDuplicationFactProvider());
        foreach ([
            new EmptyCatchRule(),
            new ErrorSwallowingRule(),
            new PlaceholderCommentsRule(),
            new PassThroughWrappersRule(),
            new DirectoryFanoutHotspotRule(),
            new OverFragmentationRule(),
            new DuplicateFunctionSignaturesRule(),
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
