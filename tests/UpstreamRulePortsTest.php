<?php

declare(strict_types=1);

namespace SlopScan\Tests;

use PHPUnit\Framework\TestCase;
use SlopScan\Analyzer;
use SlopScan\Config;
use SlopScan\DefaultRegistry;
use SlopScan\Model\Finding;

final class UpstreamRulePortsTest extends TestCase
{
    public function testGenericArrayCastsFlagsRuntimeConversionsOnVagueBagVariablesOnly(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function decode(string $raw, object $source): void
{
    $data = json_decode($raw, true);
    $payload = (array) $source;
    $record = json_decode($raw, associative: true);

    $user = json_decode($raw, true);
    $config = json_decode($raw, false);
    $result = json_decode($raw);
}
PHP);

        $evidence = array_map(
            static fn (Finding $finding): string => implode('|', $finding->evidence),
            $this->forRule($result->findings, 'php.generic-array-casts'),
        );
        sort($evidence, SORT_STRING);

        self::assertSame(
            [
                'variable=$data|kind=json-decode-assoc',
                'variable=$payload|kind=array-cast',
                'variable=$record|kind=json-decode-assoc',
            ],
            $evidence,
        );
    }

    public function testGenericArrayCastsIgnoresImmediateValidatedJsonBoundary(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function load(string $json): string
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('invalid json', 0, $exception);
    }

    if (
        !is_array($data)
        || ($data['schema_version'] ?? null) !== '1.0'
        || ($data['kind'] ?? null) !== 'execution_state'
    ) {
        throw new RuntimeException('unsupported state');
    }

    return (string) $data['task_id'];
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.generic-array-casts'));
    }

    public function testGenericArrayCastsIgnoresValidatedProjectionPayload(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function project(string $json): void
{
    $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !isset($payload['status'])) {
        return;
    }

    consume($payload['status']);
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.generic-array-casts'));
    }

    public function testGenericArrayCastsIgnoresConsumerValidatedBoundaryShapes(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function receipt(string $json): void
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('invalid json', 0, $exception);
    }

    $schema = is_array($data) ? ($data['schema_version'] ?? null) : null;
    if (!is_array($data) || !in_array($schema, ['1.0', '1.1'], true) || ($data['kind'] ?? null) !== 'receipt') {
        throw new RuntimeException('unsupported receipt');
    }

    consume($data);
}

function projectedStatus(string $json): void
{
    $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        return;
    }

    $status = $payload['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['ready', 'failed'], true)) {
        return;
    }

    consume($payload);
}

function optionalBaseline(string $json, string $taskId): ?array
{
    $data = json_decode($json, true);
    if (
        !is_array($data)
        || ($data['schema_version'] ?? null) !== '1.0'
        || ($data['task_id'] ?? null) !== $taskId
        || !is_array($data['entries'] ?? null)
    ) {
        return null;
    }

    return $data['entries'];
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.generic-array-casts'));
    }

    public function testGenericArrayCastsKeepsWeakTransportAndReturnedBagsVisible(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function transport(string $json): void
{
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        return;
    }

    $taskId = $payload['task_id'] ?? null;
    $nextAction = $payload['next_action'] ?? null;
    consume($taskId, $nextAction, $payload);
}

function weakBag(string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return is_array($data) ? $data : [];
}

function ignoredProjection(string $json): void
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $schema = is_array($data) ? ($data['schema_version'] ?? null) : null;
    if (!is_array($data) || ($data['kind'] ?? null) !== 'receipt') {
        throw new RuntimeException('unsupported receipt');
    }

    consume($schema, $data);
}

function repeatedNonThrowingKey(string $json): void
{
    $data = json_decode($json, true);
    if (!is_array($data) || ($data['kind'] ?? null) !== 'receipt' || ($data['kind'] ?? null) !== 'run') {
        return;
    }

    consume($data);
}
PHP);

        $evidence = array_map(
            static fn (Finding $finding): string => implode('|', $finding->evidence),
            $this->forRule($result->findings, 'php.generic-array-casts'),
        );
        sort($evidence, SORT_STRING);

        self::assertSame(
            [
                'variable=$data|kind=json-decode-assoc',
                'variable=$data|kind=json-decode-assoc',
                'variable=$data|kind=json-decode-assoc',
                'variable=$payload|kind=json-decode-assoc',
            ],
            $evidence,
        );
    }

    public function testGenericArrayCastsStillFlagsUncheckedOrLateValidation(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function unchecked(string $json): void
{
    $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    consume($data);
}

function late(string $json): void
{
    $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    audit();
    if (!is_array($payload) || !isset($payload['status'])) {
        return;
    }
}

function noThrowFlag(string $json): void
{
    $result = json_decode($json, true);
    if (!is_array($result) || !isset($result['status'])) {
        return;
    }
}
PHP);

        $evidence = array_map(
            static fn (Finding $finding): string => implode('|', $finding->evidence),
            $this->forRule($result->findings, 'php.generic-array-casts'),
        );
        sort($evidence, SORT_STRING);

        self::assertSame(
            [
                'variable=$data|kind=json-decode-assoc',
                'variable=$payload|kind=json-decode-assoc',
                'variable=$result|kind=json-decode-assoc',
            ],
            $evidence,
        );
    }

    public function testGenericArrayCastsDoesNotEscapeConditionalScopeOrTrustPositiveKeyCheck(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function conditional(string $json, bool $enabled): void
{
    if ($enabled) {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    if (!is_array($data) || !isset($data['status'])) {
        return;
    }
}

function backwardsGuard(string $json): void
{
    $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || isset($payload['status'])) {
        return;
    }
}
PHP);

        $evidence = array_map(
            static fn (Finding $finding): string => implode('|', $finding->evidence),
            $this->forRule($result->findings, 'php.generic-array-casts'),
        );
        sort($evidence, SORT_STRING);

        self::assertSame(
            [
                'variable=$data|kind=json-decode-assoc',
                'variable=$payload|kind=json-decode-assoc',
            ],
            $evidence,
        );
    }

    public function testGenericArrayCastsRespectsLogicalLineBudget(): void
    {
        $config = Config::defaults();
        $config['rules']['php.generic-array-casts'] = [
            'options' => ['maxFileLines' => 1],
        ];

        $result = $this->analyze(<<<'PHP'
<?php
function decode(string $raw): void
{
    $data = json_decode($raw, true);
}
PHP, $config);

        self::assertSame([], $this->forRule($result->findings, 'php.generic-array-casts'));
    }

    public function testCatchRuleAddsAssignmentAndPayloadNormalizationWithoutChangingReturnEvidence(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function direct(): string
{
    try {
        risky();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function flattened(): array
{
    try {
        risky();
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        return ['error' => (string) $exception, 'message' => $message];
    }
}
PHP);

        $evidence = array_map(
            static fn (Finding $finding): string => implode('|', $finding->evidence),
            $this->forRule($result->findings, 'php.catch-returns-exception-message'),
        );
        sort($evidence, SORT_STRING);

        self::assertSame(
            [
                'normalization=assigned-caught-message',
                'normalization=property-caught-string',
                'return=caught-message',
            ],
            $evidence,
        );
    }

    public function testCatchRuleIgnoresStructuredFailureRecords(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function structuredFailures(): array
{
    try {
        risky();
    } catch (Throwable $exception) {
        $diagnostic = [
            'code' => 'session.unreadable',
            'owner' => 'agent-session',
            'message' => $exception->getMessage(),
        ];
        $state = [
            'state' => 'invalid',
            'reason' => $exception->getMessage(),
        ];
        $status = [
            'status' => 'error',
            'error' => $exception->getMessage(),
        ];

        return [$diagnostic, $state, $status];
    }
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.catch-returns-exception-message'));
    }

    public function testCatchRuleStillFlagsMessageBesideOrdinaryPayloadData(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function payload(): array
{
    try {
        risky();
    } catch (Throwable $exception) {
        return [
            'data' => [],
            'message' => $exception->getMessage(),
        ];
    }
}
PHP);

        $findings = $this->forRule($result->findings, 'php.catch-returns-exception-message');

        self::assertCount(1, $findings);
        self::assertSame(['normalization=property-caught-message'], $findings[0]->evidence);
    }

    public function testCatchRuleLineBudgetLimitsOnlyNewAdaptation(): void
    {
        $config = Config::defaults();
        $config['rules']['php.catch-returns-exception-message'] = [
            'options' => ['maxFileLines' => 1],
        ];

        $result = $this->analyze(<<<'PHP'
<?php

function direct(): string
{
    try {
        risky();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
}

function assigned(): void
{
    try {
        risky();
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        consume($message);
    }
}
PHP, $config);

        $findings = $this->forRule($result->findings, 'php.catch-returns-exception-message');

        self::assertCount(1, $findings);
        self::assertSame(['return=caught-message'], $findings[0]->evidence);
    }

    public function testCatchRuleIgnoresDomainRecoveryAndPreviousPreservingThrows(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function recover(): string
{
    try {
        return risky();
    } catch (Throwable $exception) {
        $message = recover_message($exception);
        throw new RuntimeException($message, previous: $exception);
    }
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.catch-returns-exception-message'));
    }

    public function testCatchRuleIgnoresShadowedExceptionVariableInsideClosure(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function recover(): void
{
    try {
        risky();
    } catch (Throwable $exception) {
        $handler = static function (Throwable $exception): string {
            $message = $exception->getMessage();

            return $message;
        };

        consume($handler);
    }
}
PHP);

        self::assertSame([], $this->forRule($result->findings, 'php.catch-returns-exception-message'));
    }

    public function testCatchRuleStillFlagsCapturedCatchVariableInsideClosure(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function recover(): void
{
    try {
        risky();
    } catch (Throwable $exception) {
        $handler = static function () use ($exception): string {
            $message = $exception->getMessage();

            return $message;
        };

        consume($handler);
    }
}
PHP);

        $findings = $this->forRule($result->findings, 'php.catch-returns-exception-message');

        self::assertCount(1, $findings);
        self::assertSame(['normalization=assigned-caught-message'], $findings[0]->evidence);
    }

    public function testGenericStatusEnvelopeEvidenceIncludesRuntimeContext(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function returned(string $reason): array
{
    return ['success' => false, 'error' => $reason];
}

function response(object $response, array $rows): mixed
{
    return $response->json(['ok' => true, 'rows' => $rows]);
}

function symfony(array $rows): object
{
    return new JsonResponse(['ok' => true, 'data' => $rows]);
}

function assigned(array $rows): array
{
    $result = ['ok' => true, 'data' => $rows];
    return $result;
}
PHP);

        $contexts = array_map(
            static fn (Finding $finding): string => $finding->evidence[0],
            $this->forRule($result->findings, 'php.generic-status-envelopes'),
        );
        sort($contexts, SORT_STRING);

        self::assertSame(
            [
                'kind=assigned-generic-status-envelope',
                'kind=json-generic-status-envelope',
                'kind=json-generic-status-envelope',
                'kind=returned-generic-status-envelope',
            ],
            $contexts,
        );
    }

    public function testAdaptedRulesExposeAgentFacingMetadata(): void
    {
        $result = $this->analyze(<<<'PHP'
<?php

function converted(string $raw): void
{
    $data = json_decode($raw, true);
}

function envelope(array $rows): array
{
    return ['ok' => true, 'data' => $rows];
}
PHP);

        foreach (['php.generic-array-casts', 'php.generic-status-envelopes'] as $ruleId) {
            $finding = $this->forRule($result->findings, $ruleId)[0] ?? null;
            self::assertInstanceOf(Finding::class, $finding);
            $report = $finding->toReport();
            self::assertNotNull($report['why']);
            self::assertNotNull($report['suggestedAction']);
            self::assertSame('medium', $report['confidence']);
        }
    }

    /** @param array<string, mixed>|null $config */
    private function analyze(string $php, ?array $config = null): \SlopScan\Model\AnalysisResult
    {
        $fixture = sys_get_temp_dir() . '/slop-scan-rule-ports-' . bin2hex(random_bytes(4));
        mkdir($fixture . '/src', 0777, true);
        file_put_contents($fixture . '/src/RulePorts.php', $php);

        try {
            return (new Analyzer())->analyze(
                $fixture,
                $config ?? Config::defaults(),
                DefaultRegistry::create(),
            );
        } finally {
            $this->remove($fixture);
        }
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function forRule(array $findings, string $ruleId): array
    {
        return array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->ruleId === $ruleId,
        ));
    }

    private function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->remove($path . DIRECTORY_SEPARATOR . $item);
        }

        rmdir($path);
    }
}
