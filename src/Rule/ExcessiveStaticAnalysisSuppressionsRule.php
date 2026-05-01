<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;
use SlopScan\Support\StaticAnalysisSuppressions;

final class ExcessiveStaticAnalysisSuppressionsRule extends BaseRule
{
    private const DEFAULT_THRESHOLD = 3;
    private const MAX_EXCESSIVE_SCORE = 3.0;
    private const SCORE_MULTIPLIER = 0.5;

    public function id(): string { return 'php.excessive-static-analysis-suppressions'; }
    public function family(): string { return 'static-analysis'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $threshold = max(1, (int) ($context->ruleConfig['options']['commentCount'] ?? self::DEFAULT_THRESHOLD));
        $suppressions = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            if (StaticAnalysisSuppressions::hasAnySuppression($comment['text'])) {
                $suppressions[] = $comment;
            }
        }
        if (count($suppressions) < $threshold) {
            return [];
        }
        $first = $suppressions[0];
        $lines = array_map(static fn(array $comment): string => (string) $comment['line'], $suppressions);
        $lineEvidence = implode(',', array_slice($lines, 0, 8));
        $evidence = [
            'suppressions=' . count($suppressions),
            'threshold=' . $threshold,
            'lines=' . $lineEvidence,
        ];
        return [new Finding(
            $this->id(),
            $this->family(),
            $this->severity(),
            'file',
            'Found excessive static-analysis suppression comments in one PHP file',
            $evidence,
            min(self::MAX_EXCESSIVE_SCORE, self::SCORE_MULTIPLIER * count($suppressions)),
            [['path' => $context->file->path, 'line' => $first['line'], 'column' => 1]],
            $context->file->path
        )];
    }
}
