<?php

declare(strict_types=1);

namespace SlopScan\Support;

use SlopScan\Model\Finding;

final class FindingIgnorer
{
    /**
     * @param list<Finding>       $findings
     * @param array<string,mixed> $config
     * @return list<Finding>
     */
    public static function filter(array $findings, array $config): array
    {
        $rules = self::rules($config['ignoreErrors'] ?? []);
        if ($rules === []) {
            return $findings;
        }

        $kept = [];
        foreach ($findings as $finding) {
            if (!self::isIgnored($finding, $rules)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    /**
     * @param mixed $raw
     * @return list<array{messages:list<string>,paths:list<string>,ruleIds:list<string>,remaining:?int}>
     */
    private static function rules(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $rules[] = ['messages' => [$entry], 'paths' => [], 'ruleIds' => [], 'remaining' => null];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $messages = self::stringsFrom($entry['messages'] ?? []);
            if (is_string($entry['message'] ?? null) && $entry['message'] !== '') {
                $messages[] = $entry['message'];
            }

            $paths = self::stringsFrom($entry['paths'] ?? []);
            if (is_string($entry['path'] ?? null) && $entry['path'] !== '') {
                $paths[] = $entry['path'];
            }

            $ruleIds = self::stringsFrom($entry['ruleIds'] ?? []);
            foreach (['ruleId', 'identifier'] as $key) {
                if (is_string($entry[$key] ?? null) && $entry[$key] !== '') {
                    $ruleIds[] = $entry[$key];
                }
            }
            $ruleIds = array_merge($ruleIds, self::stringsFrom($entry['identifiers'] ?? []));

            if ($messages === [] && $paths === [] && $ruleIds === []) {
                continue;
            }

            $rules[] = [
                'messages' => array_values(array_unique($messages)),
                'paths' => array_values(array_unique($paths)),
                'ruleIds' => array_values(array_unique($ruleIds)),
                'remaining' => self::countLimit($entry['count'] ?? null),
            ];
        }

        return $rules;
    }

    /**
     * @param list<array{messages:list<string>,paths:list<string>,ruleIds:list<string>,remaining:?int}> $rules
     */
    private static function isIgnored(Finding $finding, array &$rules): bool
    {
        foreach ($rules as &$rule) {
            if (!self::matches($finding, $rule)) {
                continue;
            }

            if ($rule['remaining'] !== null) {
                if ($rule['remaining'] <= 0) {
                    continue;
                }

                --$rule['remaining'];
            }

            return true;
        }

        return false;
    }

    /**
     * @param array{messages:list<string>,paths:list<string>,ruleIds:list<string>,remaining:?int} $rule
     */
    private static function matches(Finding $finding, array $rule): bool
    {
        return self::matchesRuleId($finding, $rule['ruleIds'])
            && self::matchesMessage($finding, $rule['messages'])
            && self::matchesPath($finding, $rule['paths']);
    }

    /** @param list<string> $ruleIds */
    private static function matchesRuleId(Finding $finding, array $ruleIds): bool
    {
        return $ruleIds === [] || in_array($finding->ruleId, $ruleIds, true);
    }

    /** @param list<string> $patterns */
    private static function matchesMessage(Finding $finding, array $patterns): bool
    {
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $finding->message) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private static function matchesPath(Finding $finding, array $patterns): bool
    {
        if ($patterns === []) {
            return true;
        }

        foreach (self::findingPaths($finding) as $path) {
            foreach ($patterns as $pattern) {
                if (PatternMatcher::matches($path, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function findingPaths(Finding $finding): array
    {
        $paths = [];
        if ($finding->path !== null) {
            $paths[] = $finding->path;
        }

        foreach ($finding->locations as $location) {
            if ($location['path'] !== '') {
                $paths[] = $location['path'];
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private static function stringsFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private static function countLimit(mixed $value): ?int
    {
        return is_int($value) || is_string($value) && ctype_digit($value)
            ? max(0, (int) $value)
            : null;
    }
}
