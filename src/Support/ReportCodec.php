<?php

declare(strict_types=1);

namespace SlopScan\Support;

use HelgeSverre\Toon\EncodeOptions;
use HelgeSverre\Toon\Toon;

final class ReportCodec
{
    public static function formatForPath(?string $path, string $default = 'json'): string
    {
        if (!is_string($path) || $path === '') {
            return $default;
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'toon' => 'toon',
            default => 'json',
        };
    }

    /** @param array<string,mixed> $report */
    public static function encodeReport(array $report, string $format, bool $pretty = false): string
    {
        return match ($format) {
            'toon' => Toon::encode($report, EncodeOptions::default()),
            default => Json::encode($report, $pretty),
        };
    }

    /** @return array<string,mixed> */
    public static function decodeReport(string $encoded, string $format): array
    {
        $decoded = match ($format) {
            'toon' => Toon::decode($encoded),
            default => json_decode($encoded, true, 512, JSON_THROW_ON_ERROR),
        };

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Report payload did not decode to an object-like structure.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $report */
    public static function writeReport(string $path, array $report, bool $pretty = false): void
    {
        $encoded = self::encodeReport($report, self::formatForPath($path), $pretty);
        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new \RuntimeException("Unable to write report file: {$path}");
        }
    }

    /** @return array<string,mixed> */
    public static function readReport(string $path): array
    {
        return self::decodeReport((string) file_get_contents($path), self::formatForPath($path));
    }
}
