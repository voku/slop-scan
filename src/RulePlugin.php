<?php

declare(strict_types=1);

namespace SlopScan;

interface RulePlugin
{
    public function id(): string;
    public function family(): string;
    public function severity(): string;
    public function scope(): string;
    /** @return list<string> */
    public function requires(): array;
    public function supports(ProviderContext $context): bool;
    /** @return list<Finding> */
    public function evaluate(ProviderContext $context): array;
}
