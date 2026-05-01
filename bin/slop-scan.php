#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require __DIR__ . '/../src/bootstrap.php';
}

exit(\SlopScan\Cli::main(array_slice($argv, 1)));
