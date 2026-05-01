<?php

declare(strict_types=1);

namespace SlopScan\Rule;

use SlopScan\Contract\RulePlugin;
use SlopScan\Runtime\ProviderContext;

abstract class BaseRule implements RulePlugin
{
    public function supports(ProviderContext $context): bool { return true; }
    public function severity(): string { return 'medium'; }
}
