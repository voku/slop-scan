<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ExcessiveStaticAnalysisSuppressionsRule extends BaseRule
{
    public function id(): string { return 'php.excessive-static-analysis-suppressions'; }
    public function family(): string { return 'static-analysis'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $threshold = max(1, (int) ($context->ruleConfig['options']['commentCount'] ?? 3));
        $suppressions = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            if (preg_match('/@(phpstan-ignore(?:-(?:next-)?line)?|psalm-suppress|psalm-ignore-var|phpcsSuppress)\b/i', $comment['text'])) {
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
        return [new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found excessive static-analysis suppression comments in one PHP file', $evidence, min(3.0, 0.5 * count($suppressions)), [['path' => $context->file->path, 'line' => $first['line'], 'column' => 1]], $context->file->path)];
    }
}
