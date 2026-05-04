<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use SlopScan\Contract\FactProvider;
use SlopScan\Runtime\ProviderContext;

final class FunctionDuplicationFactProvider implements FactProvider
{
    private const IGNORED_LIFECYCLE_FUNCTIONS = [
        '__construct',
        '__destruct',
        '_before',
        '_after',
        'setup',
        'teardown',
    ];

    public function id(): string { return 'repo.functionDuplication'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.files', 'file.functionSummaries']; }
    public function provides(): array { return ['repo.duplicateFunctionSignatures', 'repo.cloneFunctionBodies']; }
    public function supports(ProviderContext $context): bool { return true; }

    public function run(ProviderContext $context): array
    {
        $signatureGroups = [];
        $bodyGroups = [];

        foreach ($context->runtime->files as $file) {
            foreach ($context->runtime->store->getFileFact($file->path, 'file.functionSummaries') ?? [] as $function) {
                $functionName = strtolower((string) ($function['name'] ?? ''));
                if (in_array($functionName, self::IGNORED_LIFECYCLE_FUNCTIONS, true)) {
                    continue;
                }

                $signatureGroups[$function['signature']][] = ['path' => $file->path, 'line' => $function['line'], 'name' => $function['name']];

                $body = $function['body'] ?? '';
                if ($body !== '' && strlen($body) >= 40) {
                    $normalized = (string) preg_replace('/\s+/', ' ', strtolower(trim($body)));
                    $bodyGroups[$normalized][] = ['path' => $file->path, 'line' => $function['line'], 'name' => $function['name']];
                }
            }
        }

        $duplicateSignatures = array_filter($signatureGroups, static fn(array $group): bool => count($group) > 1);
        ksort($duplicateSignatures, SORT_STRING);

        $cloneBodies = array_filter($bodyGroups, static fn(array $group): bool => count($group) > 1);
        ksort($cloneBodies, SORT_STRING);

        return [
            'repo.duplicateFunctionSignatures' => $duplicateSignatures,
            'repo.cloneFunctionBodies' => $cloneBodies,
        ];
    }
}
