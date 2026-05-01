<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class CommentText
{
    public static function body(string $comment): string
    {
        $body = trim($comment);
        $body = preg_replace('/^\s*(?:\/\/|#|\/\*\*?| \*)\s?/', '', $body) ?? $body;

        return trim(preg_replace('/\s*\*\/\s*$/', '', $body) ?? $body);
    }
}
