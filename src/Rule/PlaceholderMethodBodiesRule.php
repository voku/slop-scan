<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class PlaceholderMethodBodiesRule extends BaseRule
{
    private const MAX_ELISION_COMMENT_LENGTH = 80;
    private const ELISION_PATTERN = '/^\W*(?:\.{3}|…)?\s*(?:existing\s+code|rest\s+of\s+(?:the\s+)?(?:implementation|code|method|function|class|file)|(?:your\s+)?(?:code|implementation|logic)\s+(?:goes\s+)?here|remaining\s+(?:code|implementation))\s*(?:\.{3}|…)?\W*$/i';
    private const DEFERRED_MARKER_PATTERN = '/^\W*(?:todo|fixme|xxx)\b/i';
    private const TEST_PATH_PATTERN = '~(?:^|/)tests?/~i';
    private const TEST_DOUBLE_CLASS_PATTERN = '/(?:dummy|stub|fake|mock|test)\b/i';

    public function id(): string { return 'php.placeholder-method-bodies'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries', 'file.comments']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        $store = $context->runtime->store;
        $comments = $store->getFileFact($context->file->path, 'file.comments') ?? [];

        foreach ($store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            $classKind = $function['classKind'] ?? null;
            if ($classKind !== null && $classKind !== 'class') {
                continue;
            }

            $reason = $this->reason($function, $classKind, $comments, preg_match(self::TEST_PATH_PATTERN, $context->file->path) === 1);
            if ($reason === null) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                $reason['message'],
                array_merge([$function['name']], $reason['evidence']),
                1.0,
                [['path' => $context->file->path, 'line' => $function['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }

    /**
     * @param array<string,mixed> $function
     * @param list<array{text:string,line:int}> $comments
     * @return null|array{message:string,evidence:list<string>}
     */
    private function reason(array $function, ?string $classKind, array $comments, bool $isTestFile): ?array
    {
        $bodyComments = array_values(array_filter(
            $comments,
            static fn (array $comment): bool => $comment['line'] >= $function['line'] && $comment['line'] <= ($function['endLine'] ?? $function['line'])
        ));

        foreach ($bodyComments as $comment) {
            $text = trim((string) preg_replace('/^\s*(?:\/\/+|#|\/\*+|\*+)|\*+\/\s*$/', '', $comment['text']));
            if (strlen($text) <= self::MAX_ELISION_COMMENT_LENGTH && preg_match(self::ELISION_PATTERN, $text) === 1) {
                return ['message' => 'Found elision comment standing in for a method implementation', 'evidence' => ['comment=' . $text]];
            }
        }

        $className = (string) ($function['className'] ?? '');
        $isTestDouble = $isTestFile || ($className !== '' && preg_match(self::TEST_DOUBLE_CLASS_PATTERN, $className) === 1);

        $placeholderThrow = $function['placeholderThrow'] ?? null;
        if (!$isTestDouble && $placeholderThrow !== null) {
            return ['message' => 'Found method whose only behavior is a not-implemented exception', 'evidence' => ['throws=' . $placeholderThrow]];
        }

        $isTrivial = ($function['body'] ?? '') === '' || ($function['constantReturn'] ?? null) !== null;
        if ($isTrivial && !$isTestDouble) {
            foreach ($bodyComments as $comment) {
                if (preg_match(self::DEFERRED_MARKER_PATTERN, ltrim($comment['text'], "/*# \t\r\n")) === 1) {
                    return ['message' => 'Found trivial method body marked as unfinished work', 'evidence' => ['comment=' . trim($comment['text'])]];
                }
            }
        }

        if (($function['body'] ?? '') === '' && $classKind === 'class' && !str_starts_with((string) $function['name'], '__')) {
            return ['message' => 'Found empty method body in a concrete class', 'evidence' => []];
        }

        return null;
    }
}
