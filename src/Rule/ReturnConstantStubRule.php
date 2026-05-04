<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ReturnConstantStubRule extends BaseRule
{
    public function id(): string { return 'php.return-constant-stub'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [] as $function) {
            $constantReturn = $function['constantReturn'] ?? null;
            if ($constantReturn === null) {
                continue;
            }

            $classKind = $function['classKind'] ?? null;
            if (in_array($classKind, ['interface', 'abstract-class'], true)) {
                continue;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP function whose body is only a constant placeholder return',
                [$function['name'], 'return=' . $constantReturn],
                1.0,
                [['path' => $context->file->path, 'line' => $function['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
    }
}
