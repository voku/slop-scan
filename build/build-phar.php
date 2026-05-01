<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);

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
$buildDir = sys_get_temp_dir() . '/slop-scan-phar-' . bin2hex(random_bytes(4));

if (!is_dir($distDir) && !mkdir($distDir, 0777, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Unable to create {$distDir}.\n");
    exit(1);
}

if (is_file($output) && !unlink($output)) {
    fwrite(STDERR, "Unable to remove existing {$output}.\n");
    exit(1);
}

if (!mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
    fwrite(STDERR, "Unable to create temporary build directory.\n");
    exit(1);
}

try {
    foreach (['composer.json', 'composer.lock'] as $file) {
        if (!copy($rootDir . '/' . $file, $buildDir . '/' . $file)) {
            throw new RuntimeException("Unable to stage {$file} for PHAR build.");
        }
    }

    runCommand(
        ['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--optimize-autoloader', '--no-scripts'],
        $buildDir,
    );

    $files = [];

    foreach (['bin/slop-scan.php', 'composer.json'] as $relativePath) {
        $files[$relativePath] = $rootDir . '/' . $relativePath;
    }

    foreach (collectDirectoryFiles($rootDir . '/src', $rootDir) as $relativePath => $absolutePath) {
        $files[$relativePath] = $absolutePath;
    }

    foreach (collectDirectoryFiles($buildDir . '/vendor', $buildDir) as $relativePath => $absolutePath) {
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
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    removePath($buildDir);
}

/**
 * @return array<string,string>
 */
function collectDirectoryFiles(string $directory, string $baseDir): array
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
        $relativePath = ltrim(str_replace($baseDir . '/', '', $absolutePath), '/');
        $files[$relativePath] = $absolutePath;
    }

    ksort($files, SORT_STRING);

    return $files;
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, string $workingDirectory): void
{
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHAR build dependency install.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $message = trim((string) $stderr);
        if ($message === '') {
            $message = trim((string) $stdout);
        }

        throw new RuntimeException($message !== '' ? $message : 'PHAR build dependency install failed.');
    }
}

function removePath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path)) {
        unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        removePath($path . DIRECTORY_SEPARATOR . $entry);
    }

    rmdir($path);
}
