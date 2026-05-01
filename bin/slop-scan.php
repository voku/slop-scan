#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload file not found. Run composer install.\n");
    exit(1);
}

require $autoload;

exit(\SlopScan\Cli::main(array_slice($argv, 1)));
