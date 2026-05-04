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
        $ignoreErrors = $config['ignoreErrors'] ?? [];
        $rules = is_array($ignoreErrors) ? self::rules($ignoreErrors) : [];
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
     * @param array<array-key,mixed> $raw
     * @return list<array{messages:list<string>,paths:list<string>,ruleIds:list<string>,remaining:?int}>
     */
    private static function rules(array $raw): array
    {
        $rules = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && $entry !== '') {
                $rules[] = ['messages' => [$entry], 'paths' => [], 'ruleIds' => [], 'remaining' => null];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $messagesValue = $entry['messages'] ?? [];
            $messages = is_array($messagesValue) ? self::stringsFrom($messagesValue) : [];
            if (is_string($entry['message'] ?? null) && $entry['message'] !== '') {
                $messages[] = $entry['message'];
            }

            $pathsValue = $entry['paths'] ?? [];
            $paths = is_array($pathsValue) ? self::stringsFrom($pathsValue) : [];
            if (is_string($entry['path'] ?? null) && $entry['path'] !== '') {
                $paths[] = $entry['path'];
            }

            $ruleIdsValue = $entry['ruleIds'] ?? [];
            $ruleIds = is_array($ruleIdsValue) ? self::stringsFrom($ruleIdsValue) : [];
            foreach (['ruleId', 'identifier'] as $key) {
                if (is_string($entry[$key] ?? null) && $entry[$key] !== '') {
                    $ruleIds[] = $entry[$key];
                }
            }
            $identifiersValue = $entry['identifiers'] ?? [];
            if (is_array($identifiersValue)) {
                $ruleIds = array_merge($ruleIds, self::stringsFrom($identifiersValue));
            }

            if ($messages === [] && $paths === [] && $ruleIds === []) {
                continue;
            }

            $countValue = $entry['count'] ?? null;
            $rules[] = [
                'messages' => self::uniqueStrings($messages),
                'paths' => self::uniqueStrings($paths),
                'ruleIds' => self::uniqueStrings($ruleIds),
                'remaining' => is_int($countValue) || is_string($countValue) ? self::countLimit($countValue) : null,
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
            if (self::regexMatches($pattern, $finding->message)) {
                return true;
            }
        }

        return false;
    }

    private static function regexMatches(string $pattern, string $message): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($pattern, $message);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new \InvalidArgumentException("Invalid ignoreErrors message regex: {$pattern}");
        }

        return $result === 1;
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

    /**
     * @param array<array-key,mixed> $value
     * @return list<string>
     */
    private static function stringsFrom(array $value): array
    {
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param list<string> $strings
     * @return list<string>
     */
    private static function uniqueStrings(array $strings): array
    {
        return array_values(array_unique($strings));
    }

    private static function countLimit(int|string $value): ?int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return ctype_digit($value) ? max(0, (int) $value) : null;
    }
}
