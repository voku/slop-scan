<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class StaticAnalysisSuppressions
{
    private const GENERAL_PATTERN = '/@(phpstan-ignore(?:-(?:next-)?line)?|psalm-suppress|psalm-ignore-var|phpcsSuppress)\b/i';
    private const PHPSTAN_IGNORE_PATTERN = '/@(phpstan-ignore(?:-(?:next-)?line)?)\b(?<tail>[^\r\n]*)/i';
    // Keep the reason single-level so the match stays deterministic and easy to explain in findings.
    private const PHPSTAN_IGNORE_IDENTIFIER_PATTERN = '/^[a-z0-9_.-]+(?:\s+\([^()\r\n]+\))?$/i';

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

    public static function hasScopedPhpstanIgnoreTail(string $tail): bool
    {
        return preg_match(self::PHPSTAN_IGNORE_IDENTIFIER_PATTERN, trim($tail)) === 1;
    }
}
