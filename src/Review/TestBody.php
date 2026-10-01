<?php

declare(strict_types=1);

namespace SlopScan\Review;

final readonly class TestBody
{
    public function __construct(
        public string $name,
        public int $line,
        public int $assertions,
        public bool $skipped,
        public int $trivial,
        public string $hash,
    ) {
    }

    public function nonTrivialAssertions(): int
    {
        return max(0, $this->assertions - $this->trivial);
    }
}
