<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ErrorSwallowingRule extends BaseRule
{
    public function id(): string { return 'php.error-swallowing'; }
    public function family(): string { return 'error-handling'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.tryCatches']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.tryCatches') ?? [] as $catch) {
            $body = strtolower($catch['body']);
            if (preg_match('/\b(error_log|echo|print|var_dump|trigger_error)\b/', $body) && !preg_match('/\b(throw|return)\b/', $body)) {
                $findings[] = new Finding($this->id(), $this->family(), $this->severity(), 'file', 'Found PHP catch block that logs or prints and continues', ['catch body logs/prints without throw or return'], 2.0, [['path' => $context->file->path, 'line' => $catch['line'], 'column' => 1]], $context->file->path);
            }
        }
        return $findings;
    }
}
