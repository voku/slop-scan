<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class InlineFindingSuppressions
{
    private const DIRECTIVE_PATTERN = '/@slop-scan-ignore\b(?<tail>[^\r\n]*)/i';
    private const IDENTIFIER_LIST_PATTERN = '/^(?<ids>[a-z0-9_]+(?:[.-][a-z0-9_]+)*(?:\s*,\s*[a-z0-9_]+(?:[.-][a-z0-9_]+)*)*)(?:\s*\([^()\r\n]+\))?$/i';

    /**
     * @return list<array{ruleIds:list<string>,startLine:int,endLine:int}>
     */
    public static function directives(string $text): array
    {
        $directives = [];

        foreach (token_get_all($text) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $ruleIds = self::ruleIdsFromComment($token[1]);
            if ($ruleIds === []) {
                continue;
            }

            $startLine = (int) $token[2];
            $directives[] = [
                'ruleIds' => $ruleIds,
                'startLine' => $startLine,
                'endLine' => $startLine + substr_count($token[1], "\n"),
            ];
        }

        return $directives;
    }

    /**
     * @return list<string>
     */
    private static function ruleIdsFromComment(string $comment): array
    {
        if (!preg_match(self::DIRECTIVE_PATTERN, CommentText::body($comment), $match)) {
            return [];
        }

        $tail = trim($match['tail']);
        if ($tail === '' || !preg_match(self::IDENTIFIER_LIST_PATTERN, $tail, $identifierMatch)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $ruleId): string => trim($ruleId),
            explode(',', (string) $identifierMatch['ids'])
        ), static fn(string $ruleId): bool => $ruleId !== ''));
    }
}
