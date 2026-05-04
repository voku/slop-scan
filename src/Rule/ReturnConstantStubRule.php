<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class ReturnConstantStubRule extends BaseRule
{
    private const METADATA_ACCESSOR_PATTERN = '/^get_[a-z0-9_]+_(?:name|info)$/i';
    private const PREDICATE_METHOD_PATTERN = '/^(?:is|has|can|supports|should|needs|allows|must|may|was|were)[A-Z0-9_]/';
    private const TEST_DOUBLE_CLASS_PATTERN = '/(?:dummy|stub|fake|mock|test)\b/i';

    public function id(): string { return 'php.return-constant-stub'; }
    public function family(): string { return 'abstraction'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.functionSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        $functions = $context->runtime->store->getFileFact($context->file->path, 'file.functionSummaries') ?? [];
        $metadataAccessorCount = count(array_filter(
            $functions,
            static fn(array $function): bool => preg_match(self::METADATA_ACCESSOR_PATTERN, (string) ($function['name'] ?? '')) === 1
        ));

        foreach ($functions as $function) {
            $constantReturn = $function['constantReturn'] ?? null;
            if ($constantReturn === null) {
                continue;
            }

            $classKind = $function['classKind'] ?? null;
            if (in_array($classKind, ['interface', 'abstract-class'], true)) {
                continue;
            }
            if (
                $metadataAccessorCount >= 4
                && preg_match(self::METADATA_ACCESSOR_PATTERN, (string) ($function['name'] ?? '')) === 1
            ) {
                continue;
            }

            $className = (string) ($function['className'] ?? '');
            if ($className !== '' && preg_match(self::TEST_DOUBLE_CLASS_PATTERN, $className) === 1) {
                continue;
            }

            if (
                in_array($constantReturn, ['false', 'true'], true)
                && preg_match(self::PREDICATE_METHOD_PATTERN, (string) ($function['name'] ?? '')) === 1
            ) {
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
