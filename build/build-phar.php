<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$autoload = $rootDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file not found. Run composer install.\n");
    exit(1);
}

if (!extension_loaded('phar')) {
    fwrite(STDERR, "The phar extension is required to build the PHAR.\n");
    exit(1);
}

if ((bool) ini_get('phar.readonly')) {
    fwrite(STDERR, "Set phar.readonly=0 to build the PHAR.\n");
    exit(1);
}

$distDir = $rootDir . '/dist';
$output = $distDir . '/slop-scan.phar';

if (!is_dir($distDir) && !mkdir($distDir, 0777, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Unable to create {$distDir}.\n");
    exit(1);
}

if (is_file($output) && !unlink($output)) {
    fwrite(STDERR, "Unable to remove existing {$output}.\n");
    exit(1);
}

$files = [];

foreach (['bin/slop-scan.php', 'composer.json'] as $relativePath) {
    $files[$relativePath] = $rootDir . '/' . $relativePath;
}

foreach (collectDirectoryFiles($rootDir . '/src') as $relativePath => $absolutePath) {
    $files[$relativePath] = $absolutePath;
}

foreach (collectRuntimeVendorFiles($rootDir) as $relativePath => $absolutePath) {
    $files[$relativePath] = $absolutePath;
}

ksort($files, SORT_STRING);

$phar = new Phar($output, 0, 'slop-scan.phar');
$phar->startBuffering();

foreach ($files as $relativePath => $absolutePath) {
    $phar->addFile($absolutePath, $relativePath);
}

$phar->setStub(<<<'PHP'
#!/usr/bin/env php
<?php

Phar::mapPhar('slop-scan.phar');

require 'phar://slop-scan.phar/bin/slop-scan.php';

__HALT_COMPILER();
PHP);

$phar->stopBuffering();

chmod($output, 0755);

fwrite(STDOUT, "PHAR written to {$output}\n");

/**
 * @return array<string,string>
 */
function collectDirectoryFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $absolutePath = $file->getPathname();
        $relativePath = ltrim(str_replace(dirname(__DIR__) . '/', '', $absolutePath), '/');
        $files[$relativePath] = $absolutePath;
    }

    ksort($files, SORT_STRING);

    return $files;
}

/**
 * @return array<string,string>
 */
function collectRuntimeVendorFiles(string $rootDir): array
{
    $vendorDir = $rootDir . '/vendor';
    $lockFile = $rootDir . '/composer.lock';

    $files = [
        'vendor/autoload.php' => $vendorDir . '/autoload.php',
    ];

    foreach (collectDirectoryFiles($vendorDir . '/composer') as $relativePath => $absolutePath) {
        $files[$relativePath] = $absolutePath;
    }

    if (!is_file($lockFile)) {
        return $files;
    }

    $lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);

    foreach (($lock['packages'] ?? []) as $package) {
        $name = $package['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }

        $packageDir = $vendorDir . '/' . $name;

        foreach (collectDirectoryFiles($packageDir) as $relativePath => $absolutePath) {
            $files[$relativePath] = $absolutePath;
        }
    }

    ksort($files, SORT_STRING);

    return $files;
}
