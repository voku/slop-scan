<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Model\Finding;
use SlopScan\Runtime\ProviderContext;

final class MockHeavyTestsWithoutAssertionsRule extends BaseRule
{
    private const DEFAULT_MOCK_THRESHOLD = 2;
    private const MOCK_HEAVY_TEST_SCORE = 1.25;
    private const TEST_PATH_PATTERN = '#(?:^|/)(?:tests?|spec)(?:/|$)|(?:Test|TestCase)\.php$#i';
    private const TEST_CLASS_PATTERN = '/extends\s+(?:\\\\?PHPUnit\\\\Framework\\\\)?TestCase\b/';
    private const TEST_METHOD_PATTERN = '/function\s+test[A-Z0-9_][A-Za-z0-9_]*\s*\(/';
    private const TEST_ATTRIBUTE_PATTERN = '/#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b/';
    private const MOCK_PATTERN = '/\b(?:createMock|createConfiguredMock|getMockBuilder|prophesize)\s*\(|\bMockery\s*::\s*mock\s*\(/i';
    private const ASSERTION_PATTERN = '/(?:\$this|self|static)->?\s*assert[A-Z][A-Za-z0-9_]*\s*\(|\bexpectException(?:Code|Message|MessageMatches|Object)?\s*\(|\bexpectNotToPerformAssertions\s*\(/';
    private const EXPECTATION_PATTERN = '/->\s*expects\s*\(|->\s*shouldReceive\s*\(|->\s*shouldHaveReceived\s*\(/';

    public function id(): string { return 'php.mock-heavy-tests-without-assertions'; }
    public function family(): string { return 'tests'; }
    public function severity(): string { return 'weak'; }
    public function scope(): string { return 'file'; }
    public function requires(): array { return ['file.text']; }

    public function evaluate(ProviderContext $context): array
    {
        $text = (string) ($context->runtime->store->getFileFact($context->file->path, 'file.text') ?? '');
        if (!self::looksLikeTestFile($context->file->path, $text)) {
            return [];
        }

        $testCount = preg_match_all(self::TEST_METHOD_PATTERN, $text) + preg_match_all(self::TEST_ATTRIBUTE_PATTERN, $text);
        if ($testCount === 0) {
            return [];
        }

        $mockCount = preg_match_all(self::MOCK_PATTERN, $text);
        $assertionCount = preg_match_all(self::ASSERTION_PATTERN, $text);
        $expectationCount = preg_match_all(self::EXPECTATION_PATTERN, $text);
        $threshold = max(1, (int) ($context->ruleConfig['options']['mockCount'] ?? self::DEFAULT_MOCK_THRESHOLD));
        if ($mockCount < $threshold || $assertionCount > 0 || $expectationCount > 0) {
            return [];
        }

        return [new Finding(
            $this->id(),
            $this->family(),
            $this->severity(),
            'file',
            'Found mock-heavy PHP test file without assertions or mock expectations',
            [
                'tests=' . $testCount,
                'mocks=' . $mockCount,
                'assertions=' . $assertionCount,
                'expectations=' . $expectationCount,
            ],
            self::MOCK_HEAVY_TEST_SCORE,
            [['path' => $context->file->path, 'line' => 1, 'column' => 1]],
            $context->file->path
        )];
    }

    private static function looksLikeTestFile(string $path, string $text): bool
    {
        return preg_match(self::TEST_PATH_PATTERN, $path) === 1 || preg_match(self::TEST_CLASS_PATTERN, $text) === 1;
    }
}
