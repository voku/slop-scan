<?php

declare(strict_types=1);

namespace SlopScan;

abstract class BaseRule implements RulePlugin
{
    public function supports(ProviderContext $context): bool { return true; }
    public function severity(): string { return 'medium'; }
}
