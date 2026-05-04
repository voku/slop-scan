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
            'ignoreErrors' => [],
        ];
    }

    /** @return array<string,mixed> */
    public static function load(string $rootDir, ?string $configFile = null): array
    {
        $config = self::defaults();
        $root = realpath($rootDir) ?: $rootDir;
        if (is_string($configFile) && $configFile !== '') {
            $path = self::resolvePath($root, $configFile);
            if (!is_file($path)) {
                throw new \InvalidArgumentException("Config file does not exist: {$path}");
            }

            return self::loadFile($config, $path);
        }

        foreach (['slop-scan.config.json', 'repo-slop.config.json'] as $filename) {
            $path = $root . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                continue;
            }

            return self::loadFile($config, $path);
        }

        return $config;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private static function loadFile(array $config, string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded)
            ? self::merge($config, $decoded)
            : $config;
    }

    private static function resolvePath(string $rootDir, string $configFile): string
    {
        return self::isAbsolutePath($configFile)
            ? $configFile
            : $rootDir . DIRECTORY_SEPARATOR . $configFile;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $next @return array<string,mixed> */
    private static function merge(array $base, array $next): array
    {
        return [
            'ignores' => array_values(array_map('strval', $next['ignores'] ?? $base['ignores'])),
            'rules' => array_replace_recursive($base['rules'] ?? [], is_array($next['rules'] ?? null) ? $next['rules'] : []),
            'thresholds' => array_replace($base['thresholds'] ?? [], is_array($next['thresholds'] ?? null) ? $next['thresholds'] : []),
            'overrides' => is_array($next['overrides'] ?? null) ? $next['overrides'] : ($base['overrides'] ?? []),
            'ignoreErrors' => is_array($next['ignoreErrors'] ?? null) ? $next['ignoreErrors'] : ($base['ignoreErrors'] ?? []),
        ];
    }
}
