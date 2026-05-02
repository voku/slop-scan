<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class DebugOutputRule extends BaseRule
{
    private const DEBUG_OUTPUT_SCORE = 1.25;

    public function id(): string { return 'php.debug-output'; }
    public function family(): string { return 'debugging'; }
    public function severity(): string { return 'medium'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.debugCalls']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.debugCalls') ?? [] as $call) {
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
}
