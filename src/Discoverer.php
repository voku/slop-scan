<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Model\DirectoryRecord;
use SlopScan\Model\FileRecord;
use SlopScan\Support\PatternMatcher;

final class Discoverer
{
    /** @return array{files:list<FileRecord>,directories:list<DirectoryRecord>} */
    public static function discover(string $rootDir, array $config, Registry $registry): array
    {
        $files = [];
        $root = realpath($rootDir) ?: $rootDir;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $item) use ($root, $config): bool {
                    $relative = self::relativePath($root, $item->getPathname());
                    return !PatternMatcher::ignored($relative, $config['ignores'] ?? []);
                },
            ),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $relative = self::relativePath($root, $item->getPathname());
            $language = $registry->detectLanguage($relative);
            if ($language === null) {
                continue;
            }
            $files[] = new FileRecord($relative, $item->getPathname(), '.' . strtolower($item->getExtension()), 0, 0, $language->id());
        }
        usort($files, static fn(FileRecord $left, FileRecord $right): int => strcmp($left->path, $right->path));

        $byDirectory = [];
        foreach ($files as $file) {
            $directory = str_replace('\\', '/', dirname($file->path));
            $byDirectory[$directory][] = $file->path;
        }
        ksort($byDirectory, SORT_STRING);
        $directories = [];
        foreach ($byDirectory as $directory => $paths) {
            sort($paths, SORT_STRING);
            $directories[] = new DirectoryRecord($directory, $paths);
        }

        return ['files' => $files, 'directories' => $directories];
    }

    private static function relativePath(string $root, string $path): string
    {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
