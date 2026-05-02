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
        $changes = [];
        foreach ($headMap as $fingerprint => $finding) {
            if (!isset($baseMap[$fingerprint])) {
                $changes[] = ['status' => 'added', 'fingerprint' => $fingerprint, 'finding' => $finding];
            }
        }
        foreach ($baseMap as $fingerprint => $finding) {
            if (!isset($headMap[$fingerprint])) {
                $changes[] = ['status' => 'resolved', 'fingerprint' => $fingerprint, 'finding' => $finding];
            }
        }
        usort($changes, static fn(array $left, array $right): int => strcmp($left['fingerprint'], $right['fingerprint']));
        return ['summary' => ['added' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'added')), 'resolved' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'resolved'))], 'changes' => $changes];
    }

    /** @param list<array<string,mixed>> $findings @return array<string,array<string,mixed>> */
    private static function occurrenceMap(array $findings): array
    {
        $map = [];
        foreach ($findings as $finding) {
            foreach (($finding['deltaIdentity']['occurrences'] ?? []) as $occurrence) {
                $map[$occurrence['fingerprint']] = $finding;
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }
}
