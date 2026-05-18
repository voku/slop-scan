<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class StaticAnalysisSuppressions
{
    private const GENERAL_PATTERN = '/@(phpstan-ignore(?:-(?:next-)?line)?|psalm-suppress|psalm-ignore-var|phpcsSuppress)\b/i';
    private const GENERAL_WITH_TAIL_PATTERN = '/@(phpstan-ignore(?:-(?:next-)?line)?|psalm-suppress|psalm-ignore-var|phpcsSuppress)\b(?<tail>[^\r\n]*)/i';
    private const PHPSTAN_IGNORE_PATTERN = '/@(phpstan-ignore(?:-(?:next-)?line)?)\b(?<tail>[^\r\n]*)/i';
    // Keep the reason single-level so simple regex parsing can distinguish one optional reason
    // from other trailing text without nested-parenthesis handling.
    // PHPStan identifiers are treated as dot-or-hyphen separated segments such as "argument.type" or "return-type".
    // The unsuffixed PHPStan ignore directive may still carry a short single-segment identifier, so this pattern allows that shape too.
    private const PHPSTAN_IGNORE_IDENTIFIER_PATTERN = '/^[a-z0-9_]+(?:[.-][a-z0-9_]+)*(?:\s*,\s*[a-z0-9_]+(?:[.-][a-z0-9_]+)*)*(?:\s+\([^()\r\n]+\))?$/i';
    private const SCOPED_SUPPRESSION_WITH_REASON_PATTERN = '/^[a-z0-9_]+(?:[.-][a-z0-9_]+)*(?:\s*,\s*[a-z0-9_]+(?:[.-][a-z0-9_]+)*)*\s+\([^()\r\n]+\)$/i';
    private const INLINE_CONTEXT_PATTERN = '/^(?:[A-Za-z][A-Za-z0-9 _.,:-]{1,}|\([^()\r\n]+\))$/';

    public static function hasAnySuppression(string $comment): bool
    {
        return preg_match(self::GENERAL_PATTERN, $comment) === 1;
    }

    /** @return null|array{directive:string,tail:string} */
    public static function phpstanIgnoreDirective(string $comment): ?array
    {
        if (!preg_match(self::PHPSTAN_IGNORE_PATTERN, $comment, $match)) {
            return null;
        }

        return [
            'directive' => strtolower($match[1]),
            'tail' => trim((string) $match['tail']),
        ];
    }

    /** @return ?array{directive:string,tail:string} */
    public static function suppressionDirective(string $comment): ?array
    {
        if (!preg_match(self::GENERAL_WITH_TAIL_PATTERN, $comment, $match)) {
            return null;
        }

        return [
            'directive' => strtolower($match[1]),
            'tail' => trim((string) $match['tail']),
        ];
    }

    public static function hasScopedPhpstanIgnoreTail(string $tail): bool
    {
        return preg_match(self::PHPSTAN_IGNORE_IDENTIFIER_PATTERN, trim($tail)) === 1;
    }

    /**
     * Treat only scoped suppressions with a parenthesized inline reason as justified.
     * Those comments should stay out of the excessive-suppressions threshold.
     */
    public static function hasScopedSuppressionWithReason(string $tail): bool
    {
        return preg_match(self::SCOPED_SUPPRESSION_WITH_REASON_PATTERN, trim($tail)) === 1;
    }

    /**
     * Count blanket or unexplained suppressions toward the excessive-suppressions threshold.
     * Scoped suppressions with a parenthesized inline reason are intentionally ignored here.
     */
    public static function countsTowardExcessiveThreshold(string $comment): bool
    {
        $match = self::suppressionDirective(CommentText::body($comment));

        return $match !== null && !self::hasScopedSuppressionWithReason($match['tail']);
    }

    public static function hasInlineContextTail(string $tail): bool
    {
        $tail = trim($tail);
        if ($tail === '') {
            return false;
        }

        return (strlen($tail) > 1 && self::hasScopedPhpstanIgnoreTail($tail)) || preg_match(self::INLINE_CONTEXT_PATTERN, $tail) === 1;
    }
}
