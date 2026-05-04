<?php

declare(strict_types=1);

namespace SlopScan\Reporter;

use SlopScan\Contract\ReporterPlugin;
use SlopScan\Model\AnalysisResult;
use SlopScan\Support\ReportCodec;

final class ToonReporter implements ReporterPlugin
{
    public function id(): string { return 'toon'; }

    public function render(AnalysisResult $result): string
    {
        return ReportCodec::encodeReport($result->toReport(), 'toon');
    }
}
