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
    public function provides(): array
    {
        return [
            'file.comments',
            'file.functionSummaries',
            'file.tryCatches',
            'file.parserSummary',
            'file.phpDocTypeSummaries',
            'file.debugCalls',
            'file.testCallSummary',
            'file.typeEscapeSummary',
            'file.statusEnvelopes',
            'file.genericArrayCasts',
            'file.caughtExceptionNormalizations',
            'file.testMockSetups',
        ];
    }
    public function supports(ProviderContext $context): bool { return $context->file?->languageId === 'php'; }

    public function run(ProviderContext $context): array
    {
        $text = (string) $context->runtime->store->getFileFact($context->file->path, 'file.text');
        $syntax = PhpFacts::parseSyntax($text);
        $statements = $syntax['statements'] ?? [];
        $testCallSummary = PhpFacts::testCallSummary($text, $context->file->path, $statements);
        $rulePortFacts = PhpRulePortFacts::summarize($text, $testCallSummary['looksLikeTest'], $statements);
        $parserSummary = $syntax['statements'] === null
            ? [
                'available' => true,
                'classCount' => 0,
                'functionCount' => 0,
                'error' => $syntax['error'] ?? 'Unable to parse PHP source',
            ]
            : PhpFacts::parserSummaryFromStatements($statements);

        return [
            'file.comments' => PhpFacts::comments($text),
            'file.functionSummaries' => PhpFacts::functions($text, $statements),
            'file.tryCatches' => PhpFacts::tryCatches($text, $statements),
            'file.parserSummary' => $parserSummary,
            'file.phpDocTypeSummaries' => PhpFacts::phpDocTypeSummaries($context->file->absolutePath),
            'file.debugCalls' => PhpFacts::debugCalls($text, $statements),
            'file.testCallSummary' => $testCallSummary,
            'file.typeEscapeSummary' => PhpFacts::typeEscapeSummary($text, $statements),
            'file.statusEnvelopes' => $rulePortFacts['statusEnvelopes'],
            'file.genericArrayCasts' => $rulePortFacts['genericArrayCasts'],
            'file.caughtExceptionNormalizations' => $rulePortFacts['caughtExceptionNormalizations'],
            'file.testMockSetups' => $rulePortFacts['testMockSetups'],
        ];
    }
}
