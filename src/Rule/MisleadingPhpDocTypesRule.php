<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class MisleadingPhpDocTypesRule extends BaseRule
{
    public function id(): string { return 'php.misleading-phpdoc-types'; }
    public function family(): string { return 'documentation'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.phpDocTypeSummaries', 'file.text']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];
        $text = $context->runtime->store->getFileFact($context->file->path, 'file.text');
        $localTypeAliases = is_string($text) ? self::localTypeAliases($text) : [];

        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.phpDocTypeSummaries') ?? [] as $entry) {
            foreach ($entry['annotations'] as $annotation) {
                if (self::usesLocalTypeAlias($annotation['phpDocRaw'], $localTypeAliases)) {
                    continue;
                }

                $issue = self::issueForTypes(
                    $annotation['nativeType'],
                    $annotation['phpDocResolvedType'] ?? $annotation['phpDocType'],
                    $annotation['phpDocRaw'],
                    $annotation['phpDocExtendedType']
                );
                if ($issue === null) {
                    continue;
                }
                // `@param` repeats the variable between type and description, `@return` and `@var` do not.
                $repeatedVariable = $annotation['tag'] === '@param' ? $annotation['variable'] : null;
                if ($issue['kind'] === 'redundant' && self::hasDescriptionText($annotation['phpDocRaw'], $repeatedVariable)) {
                    continue;
                }

                $findings[] = new Finding(
                    $this->id(),
                    $this->family(),
                    $this->severity(),
                    'file',
                    $issue['kind'] === 'redundant'
                        ? 'Found redundant PHPDoc type annotation that repeats the native signature'
                        : 'Found misleading PHPDoc type annotation that disagrees with the native signature',
                    [
                        'subject=' . $entry['subject'],
                        'annotation=' . $annotation['annotation'],
                        'native=' . $annotation['nativeType'],
                        'phpdoc=' . $annotation['phpDocRaw'],
                        'reason=' . $issue['reason'],
                    ],
                    $issue['kind'] === 'redundant' ? 0.75 : 1.5,
                    [['path' => $context->file->path, 'line' => $annotation['line'], 'column' => 1]],
                    $context->file->path
                );
            }
        }

        return $findings;
    }

    /** @return array<string,true> */
    private static function localTypeAliases(string $text): array
    {
        $count = preg_match_all(
            '/@(?:phpstan|psalm)-(?:type|import-type)\s+([A-Za-z_][A-Za-z0-9_]*)\b/',
            $text,
            $matches,
        );
        if ($count === false || $count === 0) {
            return [];
        }

        return array_fill_keys($matches[1], true);
    }

    /** @param array<string,true> $localTypeAliases */
    private static function usesLocalTypeAlias(?string $phpDocRaw, array $localTypeAliases): bool
    {
        if ($phpDocRaw === null || $localTypeAliases === []) {
            return false;
        }

        $parts = preg_split('/\s+/', trim($phpDocRaw), 2);
        $typeExpression = $parts[0] ?? '';
        if ($typeExpression === '') {
            return false;
        }

        foreach (array_keys($localTypeAliases) as $alias) {
            if (preg_match(
                '/(?:^|[|&?(<,])\\\\?' . preg_quote($alias, '/') . '(?:$|[|&?)>\[\],])/',
                $typeExpression,
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return null|array{kind:string,reason:string} */
    private static function issueForTypes(?string $nativeType, ?string $phpDocType, ?string $phpDocRaw, ?string $phpDocExtendedType): ?array
    {
        if ($nativeType === null || $phpDocType === null || $phpDocRaw === null) {
            return null;
        }

        $native = self::canonicalType($nativeType);
        $phpDoc = self::canonicalType($phpDocType);
        if ($native === '' || $phpDoc === '') {
            return null;
        }

        if ($native === $phpDoc) {
            return self::hasAdditionalTypeValue($phpDocRaw, $phpDocExtendedType)
                ? null
                : ['kind' => 'redundant', 'reason' => 'phpdoc-repeats-native-type'];
        }

        if (self::hasAdditionalTypeValue($phpDocRaw, $phpDocExtendedType)) {
            return null;
        }

        return ['kind' => 'misleading', 'reason' => 'phpdoc-disagrees-with-native-type'];
    }

    private static function canonicalType(string $type): string
    {
        $type = trim(strtolower($type));
        if ($type === '') {
            return '';
        }

        if (str_starts_with($type, '?')) {
            $type = 'null|' . substr($type, 1);
        }

        $parts = array_filter(array_map(
            static fn(string $part): string => ltrim(trim($part), '\\'),
            explode('|', str_replace(' ', '', $type))
        ));

        // `mixed` already covers every other member of a union, including `null`.
        if (in_array('mixed', $parts, true)) {
            return 'mixed';
        }

        sort($parts, SORT_STRING);

        return implode('|', array_values(array_unique($parts)));
    }

    private static function hasAdditionalTypeValue(string $phpDocRaw, ?string $phpDocExtendedType): bool
    {
        $candidate = strtolower(trim($phpDocExtendedType ?: $phpDocRaw));

        return preg_match('/[<>{}\\[\\](),:&]/', $candidate) === 1
            || preg_match('/\b(array-key|callable-string|class-string|closed-resource|int-mask|key-of|list|literal-string|negative-int|non-empty-array|non-empty-string|numeric-string|positive-int|resource|scalar|trait-string|value-of)\b/', $candidate) === 1;
    }

    private static function hasDescriptionText(?string $phpDocRaw, ?string $paramName): bool
    {
        if ($phpDocRaw === null) {
            return false;
        }

        $raw = trim((string) preg_replace('/\s+/', ' ', $phpDocRaw));
        if ($raw === '') {
            return false;
        }

        if ($paramName !== null) {
            $pattern = '/^\S+\s+\$' . preg_quote($paramName, '/') . '(?:\s+(?<description>.+))?$/';
        } else {
            $pattern = '/^\S+(?:\s+(?<description>.+))?$/';
        }

        if (preg_match($pattern, $raw, $match) !== 1) {
            return false;
        }

        return trim((string) ($match['description'] ?? '')) !== '';
    }
}
