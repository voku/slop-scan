<?php

declare(strict_types=1);

namespace SlopScan\Contract;

use SlopScan\Model\AnalysisResult;

interface ReporterPlugin
{
    public function id(): string;
    public function render(AnalysisResult $result): string;
}
