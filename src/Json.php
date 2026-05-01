<?php

declare(strict_types=1);

namespace SlopScan;

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0));
    }
}
