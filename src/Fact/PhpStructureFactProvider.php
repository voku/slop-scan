<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use SlopScan\Contract\FactProvider;
use SlopScan\Runtime\ProviderContext;

final class PhpStructureFactProvider implements FactProvider
{
    public function id(): string { return 'php.structure'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.text']; }
    public function provides(): array { return ['file.comments', 'file.functionSummaries', 'file.tryCatches', 'file.parserSummary', 'file.phpDocTypeSummaries', 'file.debugCalls', 'file.testCallSummary']; }
    public function supports(ProviderContext $context): bool { return $context->file?->languageId === 'php'; }

    public function run(ProviderContext $context): array
    {
        $text = (string) $context->runtime->store->getFileFact($context->file->path, 'file.text');
        return [
            'file.comments' => PhpFacts::comments($text),
            'file.functionSummaries' => PhpFacts::functions($text),
            'file.tryCatches' => PhpFacts::tryCatches($text),
            'file.parserSummary' => PhpFacts::parserSummary($context->file->absolutePath),
            'file.phpDocTypeSummaries' => PhpFacts::phpDocTypeSummaries($context->file->absolutePath),
            'file.debugCalls' => PhpFacts::debugCalls($text),
            'file.testCallSummary' => PhpFacts::testCallSummary($text, $context->file->path),
        ];
    }
}
