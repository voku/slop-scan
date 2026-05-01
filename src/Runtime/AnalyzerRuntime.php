<?php

declare(strict_types=1);

namespace SlopScan\Runtime;

use SlopScan\Fact\FactStore;
use SlopScan\Model\DirectoryRecord;
use SlopScan\Model\FileRecord;

final class AnalyzerRuntime
{
    /** @param list<FileRecord> $files @param list<DirectoryRecord> $directories */
    public function __construct(
        public string $rootDir,
        public array $config,
        public array $files,
        public array $directories,
        public FactStore $store,
    ) {
    }
}
