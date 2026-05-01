<?php

declare(strict_types=1);

namespace SlopScan\Support;
final class Lines
{
    public static function physical(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return substr_count($text, "\n") + (str_ends_with($text, "\n") ? 0 : 1);
    }

    public static function logical(string $text): int
    {
        $count = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*')) {
                $count++;
            }
        }
        return $count;
    }
}
