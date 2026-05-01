<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class PassThroughWrappersRule extends BaseRule
{
    public function id(): string { return 'php.pass-through-wrappers'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            $body = trim(preg_replace('/\s+/', ' ', $function['body']) ?? '');
            if (preg_match('/^return\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\([^;]*\);?$/', $body)) {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found pass-through PHP wrapper function', [$function['name']], 1.0, [['path' => $context->file->path, 'line' => $function['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}
