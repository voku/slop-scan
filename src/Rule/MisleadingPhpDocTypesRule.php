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
    public function requires(): array { return ['file.phpDocTypeSummaries']; }

    public function evaluate(ProviderContext $context): array
    {
        $findings = [];

        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.phpDocTypeSummaries') ?? [] as $entry) {
            foreach ($entry['params'] as $param) {
                $issue = self::issueForTypes($param['nativeType'], $param['phpDocType'], $param['phpDocRaw'], $param['phpDocExtendedType']);
                if ($issue === null) {
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
                        'annotation=@param $' . $param['name'],
                        'native=' . $param['nativeType'],
                        'phpdoc=' . $param['phpDocRaw'],
                        'reason=' . $issue['reason'],
                    ],
                    $issue['kind'] === 'redundant' ? 0.75 : 1.5,
                    [['path' => $context->file->path, 'line' => $entry['line'], 'column' => 1]],
                    $context->file->path
                );
            }

            $return = $entry['return'] ?? null;
            if ($return === null) {
                continue;
            }

            $issue = self::issueForTypes($return['nativeType'], $return['phpDocType'], $return['phpDocRaw'], $return['phpDocExtendedType']);
            if ($issue === null) {
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
                    'annotation=@return',
                    'native=' . $return['nativeType'],
                    'phpdoc=' . $return['phpDocRaw'],
                    'reason=' . $issue['reason'],
                ],
                $issue['kind'] === 'redundant' ? 0.75 : 1.5,
                [['path' => $context->file->path, 'line' => $entry['line'], 'column' => 1]],
                $context->file->path
            );
        }

        return $findings;
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
        sort($parts, SORT_STRING);

        return implode('|', array_values(array_unique($parts)));
    }

    private static function hasAdditionalTypeValue(string $phpDocRaw, ?string $phpDocExtendedType): bool
    {
        $candidate = strtolower(trim($phpDocExtendedType ?: $phpDocRaw));

        return preg_match('/[<>{}\\[\\](),:&]/', $candidate) === 1
            || preg_match('/\b(array-key|callable-string|class-string|closed-resource|int-mask|key-of|list|literal-string|negative-int|non-empty-array|non-empty-string|numeric-string|positive-int|resource|scalar|trait-string|value-of)\b/', $candidate) === 1;
    }
}
