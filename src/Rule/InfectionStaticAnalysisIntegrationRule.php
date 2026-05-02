<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class InfectionStaticAnalysisIntegrationRule extends BaseRule
{
    public function id(): string { return 'php.infection-without-static-analysis'; }
    public function family(): string { return 'testing'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'repo'; }
    public function requires(): array { return ['repo.infectionStaticAnalysis']; }

    public function evaluate(ProviderContext $context): array
    {
        $summary = $context->runtime->store->getRepoFact('repo.infectionStaticAnalysis');
        if (!is_array($summary) || !($summary['usesInfection'] ?? false) || ($summary['hasStaticAnalysisIntegration'] ?? false)) {
            return [];
        }

        $path = is_string($summary['locationPath'] ?? null) ? $summary['locationPath'] : null;
        $evidence = is_array($summary['evidence'] ?? null) ? array_values(array_filter($summary['evidence'], is_string(...))) : [];

        return [
            new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'repo',
                'Found Infection mutation testing without static analysis integration',
                $evidence !== [] ? $evidence : ['missing staticAnalysisTool=phpstan|mago in Infection config or command'],
                0.75,
                [['path' => $path ?? '<repo>', 'line' => 1, 'column' => 1]],
                $path
            ),
        ];
    }
}
