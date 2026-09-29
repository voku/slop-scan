<?php

declare(strict_types=1);

namespace SlopScan;

final class BaselineCompatibility
{
    public const RULE_SEMANTICS_VERSION = 4;

    /**
     * @param array<string, mixed> $config
     * @param array{rules:list<string>,paths:list<string>,maxFindings:?int,minScore:?float} $selection
     * @return array{ruleSemanticsVersion:int,activeRuleIds:list<string>}
     */
    public static function metadata(array $config, array $selection): array
    {
        $configuredRules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $activeRuleIds = [];
        foreach (DefaultRegistry::create()->rules() as $rule) {
            $ruleId = $rule->id();
            $ruleConfig = $configuredRules[$ruleId] ?? [];
            if (is_array($ruleConfig) && ($ruleConfig['enabled'] ?? true) === false) {
                continue;
            }
            if ($selection['rules'] !== [] && !in_array($ruleId, $selection['rules'], true)) {
                continue;
            }

            $activeRuleIds[] = $ruleId;
        }

        sort($activeRuleIds, SORT_STRING);

        return [
            'ruleSemanticsVersion' => self::RULE_SEMANTICS_VERSION,
            'activeRuleIds' => $activeRuleIds,
        ];
    }

    /**
     * @param array<string, mixed> $baselineReport
     * @param array<string, mixed> $currentReport
     */
    public static function assertCompatible(array $baselineReport, array $currentReport): void
    {
        $baseline = self::readMetadata($baselineReport, 'Baseline');
        $current = self::readMetadata($currentReport, 'Current scan');

        if ($baseline['ruleSemanticsVersion'] !== $current['ruleSemanticsVersion']) {
            throw new \InvalidArgumentException(sprintf(
                'Baseline rule semantics are incompatible (baseline=%d, current=%d). Review and regenerate the baseline with the current slop-scan before comparing findings.',
                $baseline['ruleSemanticsVersion'],
                $current['ruleSemanticsVersion'],
            ));
        }

        if ($baseline['activeRuleIds'] === $current['activeRuleIds']) {
            return;
        }

        $added = array_values(array_diff($current['activeRuleIds'], $baseline['activeRuleIds']));
        $removed = array_values(array_diff($baseline['activeRuleIds'], $current['activeRuleIds']));

        throw new \InvalidArgumentException(sprintf(
            'Baseline rule surface is incompatible (added=[%s], removed=[%s]). Review and regenerate the baseline with the current slop-scan before comparing findings.',
            implode(', ', $added),
            implode(', ', $removed),
        ));
    }

    /**
     * @param array<string, mixed> $report
     * @return array{ruleSemanticsVersion:int,activeRuleIds:list<string>}
     */
    private static function readMetadata(array $report, string $label): array
    {
        $reportMetadata = $report['metadata'] ?? null;
        $metadata = is_array($reportMetadata) ? ($reportMetadata['baselineCompatibility'] ?? null) : null;
        if (!is_array($metadata)) {
            throw new \InvalidArgumentException(
                $label . ' compatibility metadata is missing. Review and regenerate the baseline with the current slop-scan before comparing findings.',
            );
        }

        $ruleSemanticsVersion = $metadata['ruleSemanticsVersion'] ?? null;
        $activeRuleIds = $metadata['activeRuleIds'] ?? null;
        if (!is_int($ruleSemanticsVersion) || !is_array($activeRuleIds)) {
            throw new \InvalidArgumentException(
                $label . ' compatibility metadata is invalid. Review and regenerate the baseline with the current slop-scan before comparing findings.',
            );
        }

        $normalizedRuleIds = [];
        foreach ($activeRuleIds as $ruleId) {
            if (!is_string($ruleId) || $ruleId === '') {
                throw new \InvalidArgumentException(
                    $label . ' compatibility metadata is invalid. Review and regenerate the baseline with the current slop-scan before comparing findings.',
                );
            }
            $normalizedRuleIds[] = $ruleId;
        }
        sort($normalizedRuleIds, SORT_STRING);

        return [
            'ruleSemanticsVersion' => $ruleSemanticsVersion,
            'activeRuleIds' => array_values(array_unique($normalizedRuleIds)),
        ];
    }
}
