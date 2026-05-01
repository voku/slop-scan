<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class StackedStaticAnalysisSuppressionsRule extends BaseRule
{
    public function id(): string { return 'php.stacked-static-analysis-suppressions'; }
    public function family(): string { return 'static-analysis'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $threshold = max(2, (int) ($context->ruleConfig['options']['commentCount'] ?? 2));
        $clusters = [];
        $current = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            if (!preg_match('/@(phpstan-ignore(?:-(?:next-)?line)?|psalm-suppress|psalm-ignore-var|phpcsSuppress)\b/i', $comment['text'])) {
                if (count($current) >= $threshold) {
                    $clusters[] = $current;
                }
                $current = [];
                continue;
            }
            if ($current !== [] && $comment['line'] !== $current[array_key_last($current)]['line'] + 1) {
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
            $lines = array_map(static fn(array $comment): string => (string) $comment['line'], $cluster);
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found stacked static-analysis suppression comments above one PHP code site',
                ['suppressions=' . count($cluster), 'lines=' . implode(',', $lines)],
                min(2.5, 0.75 * count($cluster)),
                [['path' => $context->file->path, 'line' => $cluster[0]['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }
}
