<?php

declare(strict_types=1);

namespace SlopScan;

use SlopScan\Model\Finding;

final class Delta
{
    public static function identityFor(Finding $finding): array
    {
        $occurrences = [];
        $locations = $finding->locations !== []
            ? $finding->locations
            : [['path' => $finding->path ?? '<repo>', 'line' => 1, 'column' => 1]];

        foreach ($locations as $location) {
            $key = implode(':', [$finding->ruleId, $finding->message, $location['path'], (string) $location['line']]);
            $occurrences[] = ['fingerprint' => hash('sha256', $key), 'path' => $location['path'], 'line' => $location['line'], 'column' => $location['column'] ?? 1];
        }
        return ['fingerprintVersion' => 1, 'occurrences' => $occurrences];
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $head @return array<string,mixed> */
    public static function diff(array $base, array $head): array
    {
        $baseMap = self::occurrenceMap($base['findings'] ?? []);
        $headMap = self::occurrenceMap($head['findings'] ?? []);
        $added = array_diff_key($headMap, $baseMap);
        $resolved = array_diff_key($baseMap, $headMap);
        [$resolved, $added] = self::withoutUniqueRelocations($resolved, $added);

        $changes = [];
        foreach ($added as $fingerprint => $entry) {
            $changes[] = ['status' => 'added', 'fingerprint' => $fingerprint, 'finding' => $entry['finding']];
        }
        foreach ($resolved as $fingerprint => $entry) {
            $changes[] = ['status' => 'resolved', 'fingerprint' => $fingerprint, 'finding' => $entry['finding']];
        }
        usort($changes, static fn(array $left, array $right): int => strcmp($left['fingerprint'], $right['fingerprint']));
        return ['summary' => ['added' => count($added), 'resolved' => count($resolved)], 'changes' => $changes];
    }

    /**
     * @param array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}> $resolved
     * @param array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}> $added
     * @return array{
     *     array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}>,
     *     array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}>
     * }
     */
    private static function withoutUniqueRelocations(array $resolved, array $added): array
    {
        $resolvedGroups = self::relocationGroups($resolved);
        $addedGroups = self::relocationGroups($added);
        foreach ($resolvedGroups as $key => $resolvedFingerprints) {
            $addedFingerprints = $addedGroups[$key] ?? [];
            if (count($resolvedFingerprints) !== 1 || count($addedFingerprints) !== 1) {
                continue;
            }
            unset($resolved[$resolvedFingerprints[0]], $added[$addedFingerprints[0]]);
        }

        return [$resolved, $added];
    }

    /**
     * @param array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}> $occurrences
     * @return array<string,list<string>>
     */
    private static function relocationGroups(array $occurrences): array
    {
        $groups = [];
        foreach ($occurrences as $fingerprint => $entry) {
            $groups[self::relocationKey($entry)][] = $fingerprint;
        }

        return $groups;
    }

    /** @param array{finding:array<string,mixed>,occurrence:array<string,mixed>} $entry */
    private static function relocationKey(array $entry): string
    {
        $finding = $entry['finding'];
        $occurrence = $entry['occurrence'];
        $evidence = array_values(array_filter(
            is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [],
            'is_string',
        ));

        return hash('sha256', json_encode([
            'rule_id' => $finding['ruleId'] ?? '',
            'family' => $finding['family'] ?? '',
            'scope' => $finding['scope'] ?? '',
            'message' => $finding['message'] ?? '',
            'path' => $occurrence['path'] ?? $finding['path'] ?? '<repo>',
            'evidence' => $evidence,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,array{finding:array<string,mixed>,occurrence:array<string,mixed>}>
     */
    private static function occurrenceMap(array $findings): array
    {
        $map = [];
        foreach ($findings as $finding) {
            foreach (($finding['deltaIdentity']['occurrences'] ?? []) as $occurrence) {
                $map[$occurrence['fingerprint']] = ['finding' => $finding, 'occurrence' => $occurrence];
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }
}
