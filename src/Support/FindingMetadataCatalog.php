<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class FindingMetadataCatalog
{
    /**
     * @return array{why:?string,suggestedAction:?string,confidence:?string}
     */
    public static function forRule(string $ruleId): array
    {
        return self::MAP[$ruleId] ?? ['why' => null, 'suggestedAction' => null, 'confidence' => null];
    }

    /** @var array<string,array{why:string,suggestedAction:string,confidence:string}> */
    private const MAP = [
        'php.empty-catch' => [
            'why' => 'Empty catch blocks hide failures and remove debugging context.',
            'suggestedAction' => 'Handle the exception deliberately, return an explicit fallback, or rethrow with context.',
            'confidence' => 'high',
        ],
        'php.exception-wrap-without-previous' => [
            'why' => 'Wrapping an exception without passing the previous error loses the original stack and type.',
            'suggestedAction' => 'Pass the caught exception as previous or keep the original exception if no extra context is needed.',
            'confidence' => 'high',
        ],
        'php.error-obscuring-catch' => [
            'why' => 'Replacing an exception with a generic one can hide the actual failure cause.',
            'suggestedAction' => 'Preserve the original exception as previous or keep the original type when possible.',
            'confidence' => 'high',
        ],
        'php.error-swallowing' => [
            'why' => 'Logging or printing an error and then continuing can turn a broken path into a silent failure.',
            'suggestedAction' => 'Stop execution, return an explicit fallback, or rethrow after logging.',
            'confidence' => 'high',
        ],
        'php.blanket-static-analysis-suppressions' => [
            'why' => 'Broad static-analysis suppressions can hide unrelated issues and reduce trust in the analysis.',
            'suggestedAction' => 'Use a scoped identifier with a reason or refactor the code so the suppression is no longer needed.',
            'confidence' => 'medium',
        ],
        'php.excessive-static-analysis-suppressions' => [
            'why' => 'Many suppressions in one file usually signal structural debt or repeated papering over of type problems.',
            'suggestedAction' => 'Cluster the suppressions by cause and fix the highest-volume pattern first.',
            'confidence' => 'medium',
        ],
        'php.stacked-static-analysis-suppressions' => [
            'why' => 'Stacked suppression comments often indicate one code site is resisting cleanup.',
            'suggestedAction' => 'Refactor that code site or replace the stack with one precise, justified suppression.',
            'confidence' => 'high',
        ],
        'php.commented-out-code' => [
            'why' => 'Disabled code in comments adds noise and makes it unclear which path is still authoritative.',
            'suggestedAction' => 'Delete the dead code, move the example to docs, or keep only the intent as prose.',
            'confidence' => 'medium',
        ],
        'php.catch-default-fallbacks' => [
            'why' => 'Returning an empty default from a catch block can hide a real failure behind a plausible value.',
            'suggestedAction' => 'Use an explicit error path or document and justify the fallback at the boundary.',
            'confidence' => 'high',
        ],
        'php.catch-returns-exception-message' => [
            'why' => 'Returning exception text as a normal value blurs success and failure paths.',
            'suggestedAction' => 'Return a structured error result or throw instead of converting the exception to a plain string.',
            'confidence' => 'high',
        ],
        'php.debug-output' => [
            'why' => 'Leftover debug output is usually accidental and can leak internal state.',
            'suggestedAction' => 'Remove the debug call or replace it with deliberate logging or a test assertion.',
            'confidence' => 'high',
        ],
        'php.mock-heavy-tests-without-assertions' => [
            'why' => 'A mock-heavy test without behavior assertions often exercises setup more than behavior.',
            'suggestedAction' => 'Assert observable behavior or simplify the test to the boundary you actually care about.',
            'confidence' => 'medium',
        ],
        'php.misleading-phpdoc-types' => [
            'why' => 'Misaligned or redundant PHPDoc reduces trust in the type contract and adds maintenance noise.',
            'suggestedAction' => 'Keep only PHPDoc that adds information beyond the native signature.',
            'confidence' => 'medium',
        ],
        'php.placeholder-comments' => [
            'why' => 'Placeholder comments often mark unfinished work, deferred cleanup, or intentionally rough edges.',
            'suggestedAction' => 'Implement the missing work, turn the note into tracked context, or suppress generated legacy paths.',
            'confidence' => 'medium',
        ],
        'php.pass-through-wrappers' => [
            'why' => 'Thin wrappers can add indirection without adding a real seam or invariant.',
            'suggestedAction' => 'Inline the call or add real behavior that justifies the wrapper boundary.',
            'confidence' => 'medium',
        ],
        'php.directory-fanout-hotspot' => [
            'why' => 'A very large directory can be harder to review and navigate safely.',
            'suggestedAction' => 'Check whether the directory wants sub-grouping, extraction, or clearer ownership boundaries.',
            'confidence' => 'low',
        ],
        'php.over-fragmentation' => [
            'why' => 'Too many tiny files can make simple behavior harder to follow than necessary.',
            'suggestedAction' => 'Merge trivial fragments where the split does not provide a useful seam.',
            'confidence' => 'low',
        ],
        'php.duplicate-function-signatures' => [
            'why' => 'Repeated signatures can point to copy-paste design or missed shared abstractions.',
            'suggestedAction' => 'Review the repeated API shape and decide whether a common contract or helper is warranted.',
            'confidence' => 'low',
        ],
        'php.return-constant-stub' => [
            'why' => 'A single constant return can indicate a stub, placeholder, or intentionally inert implementation.',
            'suggestedAction' => 'Confirm the dummy implementation is intentional; otherwise replace it with real behavior or a clearer contract.',
            'confidence' => 'medium',
        ],
        'php.placeholder-method-bodies' => [
            'why' => 'Empty concrete method bodies often signal a forgotten implementation or a too-weak abstraction.',
            'suggestedAction' => 'Implement the method, make the contract abstract, or document why the no-op is intentional.',
            'confidence' => 'high',
        ],
        'php.clone-cluster' => [
            'why' => 'Near-identical bodies across multiple functions often come from copy-paste evolution.',
            'suggestedAction' => 'Review whether the shared logic should move into one helper or a clearer abstraction.',
            'confidence' => 'medium',
        ],
        'php.type-escape-hotspots' => [
            'why' => 'Many mixed types and casts in one file usually indicate type friction at that boundary.',
            'suggestedAction' => 'Tighten the data contract or isolate the conversion boundary into a smaller, named adapter.',
            'confidence' => 'medium',
        ],
        'markdown.low-signal' => [
            'why' => 'Generic process-heavy markdown with few code, path, or command anchors often adds review noise without preserving durable knowledge.',
            'suggestedAction' => 'Fold the note into an existing durable doc, add concrete repository references, or delete the file if it only restates obvious work.',
            'confidence' => 'medium',
        ],
    ];
}
