<?php

declare(strict_types=1);

namespace SlopScan;

final class JsonReporter implements ReporterPlugin
{
    public function id(): string { return 'json'; }
    public function render(AnalysisResult $result): string { return Json::encode($result->toReport(), true); }
}
