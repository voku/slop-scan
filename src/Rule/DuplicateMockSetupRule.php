<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class DuplicateMockSetupRule extends BaseRule
{
    public function id(): string { return 'php.duplicate-mock-setup'; }
    public function family(): string { return 'tests'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.duplicateMockSetups']; }

    public function evaluate(ProviderContext $context): array
    {
        $clusters = $context->runtime->store->getRepoFact('repo.duplicateMockSetups') ?? [];
        if (!is_array($clusters)) {
            return [];
        }

        /** @var array<string,list<array{label:string,fileCount:int,occurrences:list<array{path:string,line:int}>}>> $byPath */
        $byPath = [];
        foreach ($clusters as $cluster) {
            if (!is_array($cluster)
                || !is_string($cluster['label'] ?? null)
                || !is_int($cluster['fileCount'] ?? null)
                || !is_array($cluster['occurrences'] ?? null)
            ) {
                continue;
            }

            $paths = [];
            $occurrences = [];
            foreach ($cluster['occurrences'] as $occurrence) {
                if (!is_array($occurrence) || !is_string($occurrence['path'] ?? null) || !is_int($occurrence['line'] ?? null)) {
                    continue;
                }
                $paths[$occurrence['path']] = true;
                $occurrences[] = ['path' => $occurrence['path'], 'line' => $occurrence['line']];
            }

            foreach (array_keys($paths) as $path) {
                $byPath[$path][] = [
                    'label' => $cluster['label'],
                    'fileCount' => $cluster['fileCount'],
                    'occurrences' => $occurrences,
                ];
            }
        }
        ksort($byPath, SORT_STRING);

        $findings = [];
        foreach ($byPath as $path => $pathClusters) {
            $evidence = [];
            $locations = [];
            $score = 0.0;
            foreach ($pathClusters as $cluster) {
                $evidence[] = 'setup=' . $cluster['label'] . '|files=' . $cluster['fileCount'];
                $score += 1.0 + max(0, $cluster['fileCount'] - 2) * 0.5;
                foreach ($cluster['occurrences'] as $occurrence) {
                    $locations[$occurrence['path'] . ':' . $occurrence['line']] = [
                        'path' => $occurrence['path'],
                        'line' => $occurrence['line'],
                        'column' => 1,
                    ];
                }
            }

            sort($evidence, SORT_STRING);
            ksort($locations, SORT_STRING);
            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found duplicated PHPUnit mock/setup pattern' . (count($pathClusters) === 1 ? '' : 's'),
                $evidence,
                min(5.0, $score),
                array_values($locations),
                $path,
            );
        }

        return $findings;
    }
}
