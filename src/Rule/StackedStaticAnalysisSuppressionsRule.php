<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;
use SlopScan\Support\StaticAnalysisSuppressions;

final class StackedStaticAnalysisSuppressionsRule extends BaseRule
{
    private const DEFAULT_THRESHOLD = 2;
    private const MINIMUM_THRESHOLD = 2;
    private const MAX_CLUSTER_SCORE = 2.5;
    private const SCORE_MULTIPLIER = 0.75;

    public function id(): string { return 'php.stacked-static-analysis-suppressions'; }
    public function family(): string { return 'static-analysis'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $threshold = max(self::MINIMUM_THRESHOLD, (int) ($context->ruleConfig['options']['commentCount'] ?? self::DEFAULT_THRESHOLD));
        $clusters = [];
        $current = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            if (!StaticAnalysisSuppressions::hasAnySuppression($comment['text'])) {
                if (count($current) >= $threshold) {
                    $clusters[] = $current;
                }
                $current = [];
                continue;
            }
            $lastComment = $current === [] ? null : $current[array_key_last($current)];
            if ($lastComment !== null && $comment['line'] !== $lastComment['line'] + 1) {
                if (count($current) >= $threshold) {
                    $clusters[] = $current;
                }
                $current = [];
            }
            $current[] = $comment;
        }
        if (count($current) >= $threshold) {
            $clusters[] = $current;
        }
        if ($clusters === []) {
            return [];
        }

        $findings = [];
        foreach ($clusters as $cluster) {
            $lines = implode(',', array_column($cluster, 'line'));
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found stacked static-analysis suppression comments above one PHP code site',
                ['suppressions=' . count($cluster), 'lines=' . $lines],
                min(self::MAX_CLUSTER_SCORE, self::SCORE_MULTIPLIER * count($cluster)),
                [['path' => $context->file->path, 'line' => $cluster[0]['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }
}
