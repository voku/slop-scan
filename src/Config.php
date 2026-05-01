<?php

declare(strict_types=1);

namespace SlopScan;
final class Config
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'ignores' => ['**/vendor/**', '**/.git/**', '**/node_modules/**', '**/dist/**', '**/coverage/**', '**/*.generated.*'],
            'rules' => [],
            'thresholds' => [],
            'overrides' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function load(string $rootDir): array
    {
        $config = self::defaults();
        foreach (['slop-scan.config.json', 'repo-slop.config.json'] as $filename) {
            $path = $rootDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $config = self::merge($config, $decoded);
            }
            break;
        }
        return $config;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $next @return array<string,mixed> */
    private static function merge(array $base, array $next): array
    {
        return [
            'ignores' => array_values(array_map('strval', $next['ignores'] ?? $base['ignores'])),
            'rules' => array_replace_recursive($base['rules'] ?? [], is_array($next['rules'] ?? null) ? $next['rules'] : []),
            'thresholds' => array_replace($base['thresholds'] ?? [], is_array($next['thresholds'] ?? null) ? $next['thresholds'] : []),
            'overrides' => is_array($next['overrides'] ?? null) ? $next['overrides'] : ($base['overrides'] ?? []),
        ];
    }
}
