<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;
use SlopScan\Support\StaticAnalysisSuppressions;

final class BlanketStaticAnalysisSuppressionsRule extends BaseRule
{
    private const BLANKET_SUPPRESSION_SCORE = 0.75;

    public function id(): string { return 'php.blanket-static-analysis-suppressions'; }
    public function family(): string { return 'static-analysis'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            $text = self::commentBody($comment['text']);
            $match = StaticAnalysisSuppressions::phpstanIgnoreDirective($text);
            if ($match === null) {
                continue;
            }
            $tail = $match['tail'];
            $directive = $match['directive'];
            if ($directive === 'phpstan-ignore' && $tail !== '') {
                continue;
            }
            if ($directive !== 'phpstan-ignore' && $tail !== '' && preg_match('/[A-Za-z0-9_\\\\.-]/', $tail)) {
                continue;
            }
            $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found blanket PHPStan suppression without an identifier or reason', [trim($comment['text'])], self::BLANKET_SUPPRESSION_SCORE, [['path' => $context->file->path, 'line' => $comment['line'], 'column' => 1]], $context->file->path);
        }
        return $findings;
    }

    private static function commentBody(string $comment): string
    {
        $body = trim($comment);
        $body = preg_replace('/^\s*(?:\/\/|#|\/\*\*?| \*)\s?/', '', $body) ?? $body;
        return trim(preg_replace('/\s*\*\/\s*$/', '', $body) ?? $body);
    }
}
