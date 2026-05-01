<?php

declare(strict_types=1);

namespace SlopScan;

interface ReporterPlugin
{
    public function id(): string;
    public function render(AnalysisResult $result): string;
}
