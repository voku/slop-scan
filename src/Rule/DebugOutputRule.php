<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class DebugOutputRule extends BaseRule
{
    private const DEBUG_OUTPUT_SCORE = 1.25;
    private const DEBUG_FUNCTIONS = ['var_dump', 'print_r', 'dump', 'dd', 'ray'];

    public function id(): string { return 'php.debug-output'; }
    public function family(): string { return 'debugging'; }
    public function severity(): string { return 'medium'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.text']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach (self::debugCalls((string) $context->runtime->store->getFileFact($context->file->path, 'file.text')) as $call) {
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP debug-output call left in source',
                [$call['name']],
                self::DEBUG_OUTPUT_SCORE,
                [['path' => $context->file->path, 'line' => $call['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }

    /** @return list<array{name:string,line:int}> */
    private static function debugCalls(string $text): array
    {
        $calls = [];
        $tokens = token_get_all($text);
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            if (!in_array($name, self::DEBUG_FUNCTIONS, true)) {
                continue;
            }

            $previous = self::previousMeaningfulToken($tokens, $index);
            if (is_array($previous) && $previous[0] === T_FUNCTION) {
                continue;
            }
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            if (self::nextMeaningfulToken($tokens, $index) !== '(') {
                continue;
            }

            $calls[] = ['name' => $name, 'line' => $token[2]];
        }

        return $calls;
    }

    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }

        return null;
    }

    private static function nextMeaningfulToken(array $tokens, int $index): array|string|null
    {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }

        return null;
    }
}
