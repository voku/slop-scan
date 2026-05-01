<?php

declare(strict_types=1);

namespace SlopScan\Runtime;

use SlopScan\Model\DirectoryRecord;
use SlopScan\Model\FileRecord;

final class ProviderContext
{
    public function __construct(
        public string $scope,
        public AnalyzerRuntime $runtime,
        public ?FileRecord $file = null,
        public ?DirectoryRecord $directory = null,
        public array $ruleConfig = ['enabled' => true, 'weight' => 1.0],
    ) {
    }
}
