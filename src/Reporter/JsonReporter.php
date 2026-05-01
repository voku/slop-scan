<?php

declare(strict_types=1);

namespace SlopScan\Reporter;

use SlopScan\Contract\ReporterPlugin;
use SlopScan\Model\AnalysisResult;
use SlopScan\Support\Json;

final class JsonReporter implements ReporterPlugin
{
    public function id(): string { return 'json'; }
    public function render(AnalysisResult $result): string { return Json::encode($result->toReport(), true); }
}
