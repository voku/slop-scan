<?php

declare(strict_types=1);

namespace SlopScan\Support;

final class ScanCache
{
    private const FORMAT_VERSION = 1;
    private const PROVIDER_SCHEMA_VERSIONS = [
        'php.structure' => 1,
    ];

    /** @var array<string,array<string,array{fingerprint:string,facts:array<string,mixed>}>> */
    private array $fileProviders = [];

    private function __construct(
        private readonly ?string $path,
    ) {
    }

    public static function defaultPath(string $rootDir): string
    {
        $root = realpath($rootDir) ?: $rootDir;

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.slop-scan.cache.json';
    }

    public static function load(?string $path): self
    {
        $cache = new self($path);
        if ($path === null || $path === '' || !is_file($path)) {
            return $cache;
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                return $cache;
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $cache;
        }

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::FORMAT_VERSION || !is_array($decoded['fileProviders'] ?? null)) {
            return $cache;
        }

        foreach ($decoded['fileProviders'] as $providerId => $entries) {
            if (!is_string($providerId) || !self::supportsProvider($providerId) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $filePath => $entry) {
                if (!is_string($filePath) || !is_array($entry)) {
                    continue;
                }

                $fingerprint = $entry['fingerprint'] ?? null;
                $facts = $entry['facts'] ?? null;
                if (!is_string($fingerprint) || !is_array($facts)) {
                    continue;
                }

                $cache->fileProviders[$providerId][$filePath] = [
                    'fingerprint' => $fingerprint,
                    'facts' => $facts,
                ];
            }

            ksort($cache->fileProviders[$providerId], SORT_STRING);
        }

        ksort($cache->fileProviders, SORT_STRING);

        return $cache;
    }

    /** @return null|array<string,mixed> */
    public function getFileProviderFacts(string $providerId, string $filePath, string $contentHash): ?array
    {
        if (!self::supportsProvider($providerId)) {
            return null;
        }

        $entry = $this->fileProviders[$providerId][$filePath] ?? null;
        if (!is_array($entry) || ($entry['fingerprint'] ?? null) !== self::fingerprint($providerId, $contentHash)) {
            return null;
        }

        return is_array($entry['facts'] ?? null) ? $entry['facts'] : null;
    }

    /** @param array<string,mixed> $facts */
    public function setFileProviderFacts(string $providerId, string $filePath, string $contentHash, array $facts): void
    {
        if ($this->path === null || $this->path === '' || !self::supportsProvider($providerId)) {
            return;
        }

        $this->fileProviders[$providerId][$filePath] = [
            'fingerprint' => self::fingerprint($providerId, $contentHash),
            'facts' => $facts,
        ];
        ksort($this->fileProviders[$providerId], SORT_STRING);
        ksort($this->fileProviders, SORT_STRING);
    }

    public function persist(): void
    {
        if ($this->path === null || $this->path === '') {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            return;
        }

        $payload = [
            'version' => self::FORMAT_VERSION,
            'fileProviders' => $this->fileProviders,
        ];

        $temporaryPath = $this->path . '.tmp';

        try {
            file_put_contents($temporaryPath, Json::encode($payload, true));
            rename($temporaryPath, $this->path);
        } catch (\Throwable) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private static function supportsProvider(string $providerId): bool
    {
        return isset(self::PROVIDER_SCHEMA_VERSIONS[$providerId]);
    }

    private static function fingerprint(string $providerId, string $contentHash): string
    {
        return hash('sha256', $providerId . ':' . self::PROVIDER_SCHEMA_VERSIONS[$providerId] . ':' . $contentHash);
    }
}
