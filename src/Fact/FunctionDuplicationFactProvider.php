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
    public function requires(): array { return ['repo.files', 'file.functionSummaries', 'file.testMockSetups']; }
    public function provides(): array { return ['repo.duplicateFunctionSignatures', 'repo.cloneFunctionBodies', 'repo.duplicateMockSetups']; }
    public function supports(ProviderContext $context): bool { return true; }

    public function run(ProviderContext $context): array
    {
        $signatureGroups = [];
        $bodyGroups = [];
        /** @var array<string,array{fingerprint:string,label:string,occurrences:list<array{path:string,line:int}>}> $mockSetupGroups */
        $mockSetupGroups = [];

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
                    $bodyGroups[$normalized][] = [
                        'path' => $file->path,
                        'line' => $function['line'],
                        'name' => $function['name'],
                        'namespaceName' => $function['namespaceName'] ?? null,
                    ];
                }
            }

            foreach ($context->runtime->store->getFileFact($file->path, 'file.testMockSetups') ?? [] as $setup) {
                if (!is_array($setup)) {
                    continue;
                }

                $fingerprint = $setup['fingerprint'] ?? null;
                $label = $setup['label'] ?? null;
                $line = $setup['line'] ?? null;
                if (!is_string($fingerprint) || $fingerprint === '' || !is_string($label) || !is_int($line)) {
                    continue;
                }

                $mockSetupGroups[$fingerprint] ??= [
                    'fingerprint' => $fingerprint,
                    'label' => $label,
                    'occurrences' => [],
                ];
                $mockSetupGroups[$fingerprint]['occurrences'][] = ['path' => $file->path, 'line' => $line];
            }
        }

        $duplicateSignatures = array_filter($signatureGroups, static fn(array $group): bool => count($group) > 1);
        ksort($duplicateSignatures, SORT_STRING);

        $cloneBodies = array_filter($bodyGroups, static fn(array $group): bool => count($group) > 1);
        ksort($cloneBodies, SORT_STRING);

        /** @var list<array{fingerprint:string,label:string,fileCount:int,occurrences:list<array{path:string,line:int}>}> $duplicateMockSetups */
        $duplicateMockSetups = [];
        foreach ($mockSetupGroups as $group) {
            $filePaths = array_values(array_unique(array_column($group['occurrences'], 'path')));
            if (count($filePaths) < 3) {
                continue;
            }

            usort(
                $group['occurrences'],
                static fn (array $left, array $right): int => strcmp($left['path'], $right['path']) ?: ($left['line'] <=> $right['line']),
            );
            $duplicateMockSetups[] = [
                'fingerprint' => $group['fingerprint'],
                'label' => $group['label'],
                'fileCount' => count($filePaths),
                'occurrences' => $group['occurrences'],
            ];
        }
        usort(
            $duplicateMockSetups,
            static fn (array $left, array $right): int => ($right['fileCount'] <=> $left['fileCount'])
                ?: strcmp($left['fingerprint'], $right['fingerprint']),
        );

        return [
            'repo.duplicateFunctionSignatures' => $duplicateSignatures,
            'repo.cloneFunctionBodies' => $cloneBodies,
            'repo.duplicateMockSetups' => $duplicateMockSetups,
        ];
    }
}
