<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class PlaceholderMethodBodiesRule extends BaseRule
{
    public function id(): string { return 'php.placeholder-method-bodies'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            if (($function['classKind'] ?? null) !== 'class') {
                continue;
            }

            if (($function['body'] ?? '') !== '') {
                continue;
            }

            if (str_starts_with($function['name'], '__')) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found empty method body in a concrete class',
                [$function['name']],
                1.0,
                [['path' => $context->file->path, 'line' => $function['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }
}
