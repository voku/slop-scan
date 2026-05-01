<?php

declare(strict_types=1);

namespace SlopScan;

final class PatternMatcher
{
    /** @param list<string> $patterns */
    public static function ignored(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $path, string $pattern): bool
    {
        $path = str_replace('\\', '/', $path);
        $pattern = str_replace('\\', '/', $pattern);
        $regex = preg_quote($pattern, '~');
        $regex = str_replace(['\*\*/', '\*\*', '\*'], ['(?:.*/)?', '.*', '[^/]*'], $regex);
        return (bool) preg_match('~^' . $regex . '$~', $path);
    }
}
