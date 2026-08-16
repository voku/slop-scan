<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class GenericStatusEnvelopesRule extends BaseRule
{
    private const DEFAULT_MAX_FILE_LINES = 5000;

    public function id(): string { return 'php.generic-status-envelopes'; }
    public function family(): string { return 'api'; }
    public function scope(): string { return 'file'; }
    public function requires(): array
    {
        return [
            'file.statusEnvelopes',
            'file.statusEnvelopeContexts',
            'file.logicalLineCount',
        ];
    }

    public function evaluate(ProviderContext $context): array
    {
        $maxFileLines = max(1, (int) ($context->ruleConfig['options']['maxFileLines'] ?? self::DEFAULT_MAX_FILE_LINES));
        $logicalLineCount = (int) $context->runtime->store->getFileFact($context->file->path, 'file.logicalLineCount');
        if ($logicalLineCount > $maxFileLines) {
            return [];
        }

        $contexts = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.statusEnvelopeContexts') ?? [] as $entry) {
            $contexts[$entry['line'] . ':' . $entry['column']] = $entry['kind'];
        }

        $findings = [];
        foreach ($context->runtime->store->getFileFact($context->file->path, 'file.statusEnvelopes') ?? [] as $envelope) {
            $contextKey = $envelope['line'] . ':' . $envelope['column'];
            $evidence = [
                'kind=' . ($contexts[$contextKey] ?? 'assigned-generic-status-envelope'),
                'status=' . $envelope['statusKey'] . ':' . $envelope['statusValue'],
            ];
            foreach ($envelope['payloadKeys'] as $payloadKey) {
                $evidence[] = 'payload=' . $payloadKey;
            }

            $findings[] = new Finding(
                $this->id(),
                $this->family(),
                $this->severity(),
                'file',
                'Found PHP array literal shaped as a generic status envelope',
                $evidence,
                2.0,
                [['path' => $context->file->path, 'line' => $envelope['line'], 'column' => $envelope['column']]],
                $context->file->path
            );
        }

        return $findings;
    }
}
