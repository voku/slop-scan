<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class CommentedOutCodeRule extends BaseRule
{
    private const COMMENTED_OUT_CODE_SCORE = 0.75;
    private const CODE_PATTERN = '/^(?:<\?php\b|return\b|throw\b|if\s*\(|elseif\s*\(|foreach\s*\(|for\s*\(|while\s*\(|switch\s*\(|case\b|try\b|catch\s*\(|\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])?\s*=|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\([^)]*\)\s*;|\}\s*else\b)/i';

    public function id(): string { return 'php.commented-out-code'; }
    public function family(): string { return 'comments'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.comments') ?? [] as $comment) {
            $body = self::commentBody($comment['text']);
            if ($body === '' || !preg_match(self::CODE_PATTERN, $body) || !preg_match('/[;{}]/', $body)) {
                continue;
            }
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP comment that looks like disabled code',
                [trim($comment['text'])],
                self::COMMENTED_OUT_CODE_SCORE,
                [['path' => $context->file->path, 'line' => $comment['line'], 'column' => 1]],
                $context->file->path
            );
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
